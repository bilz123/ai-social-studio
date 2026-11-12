"""Runs a generation: submit to a provider, poll with backoff, retry, fall back, QA, store."""

from __future__ import annotations

import asyncio
import logging
import tempfile
import time
from collections.abc import Awaitable, Callable
from dataclasses import dataclass, field
from pathlib import Path
from typing import Any

from studio_common.errors import ServiceError
from studio_common.jobs import JobContext
from studio_common.media import MediaInfo, probe
from studio_common.storage import Storage

from video_generator.providers.base import (
    ImageToVideoRequest,
    ProviderError,
    ProviderJob,
    ProviderRejected,
    ProviderStatus,
    VideoProvider,
)
from video_generator.schemas import ClipResult, GenerationRequest, GenerationResult, Shot
from video_generator.settings import VideoGeneratorSettings

logger = logging.getLogger(__name__)

QA_DURATION_TOLERANCE = 0.3


class CircuitBreaker:
    """Stops sending work to a provider that keeps failing, for a cooldown period."""

    def __init__(
        self, threshold: int, cooldown: float, clock: Callable[[], float] = time.monotonic
    ):
        self._threshold = threshold
        self._cooldown = cooldown
        self._clock = clock
        self._failures = 0
        self._open_until = 0.0

    @property
    def is_open(self) -> bool:
        return self._clock() < self._open_until

    def record_success(self) -> None:
        self._failures = 0

    def record_failure(self) -> None:
        self._failures += 1
        if self._failures >= self._threshold:
            self._open_until = self._clock() + self._cooldown
            self._failures = 0


@dataclass
class Budget:
    limit_cents: float
    spent: float = 0.0

    def check(self, estimate: float) -> None:
        if self.spent + estimate > self.limit_cents + 1e-9:
            raise ServiceError(
                422, "cost_limit_exceeded", "This video would cost more than the allowed limit."
            )


@dataclass
class _Shared:
    budget: Budget
    shots: dict[str, dict[str, Any]] = field(default_factory=dict)
    provider_jobs: list[str] = field(default_factory=list)
    done: int = 0


Sleep = Callable[[float], Awaitable[None]]


class GenerationEngine:
    def __init__(
        self,
        providers: dict[str, VideoProvider],
        settings: VideoGeneratorSettings,
        storage: Storage,
        *,
        sleep: Sleep = asyncio.sleep,
        clock: Callable[[], float] = time.monotonic,
    ) -> None:
        self.providers = providers
        self._settings = settings
        self._storage = storage
        self._sleep = sleep
        self._clock = clock
        self._slots = asyncio.Semaphore(settings.max_provider_concurrency)
        self.breakers = {
            name: CircuitBreaker(
                settings.breaker_failures, settings.breaker_cooldown_seconds, clock
            )
            for name in providers
        }

    def chain(self, requested: str | None) -> list[str]:
        order = [requested or self._settings.video_provider_primary]
        if self._settings.video_provider_fallback:
            order.append(self._settings.video_provider_fallback)
        seen: list[str] = []
        for name in order:
            if name in self.providers and name not in seen:
                seen.append(name)
        return seen

    async def run(self, request: GenerationRequest, ctx: JobContext) -> dict[str, Any]:
        chain = self.chain(request.provider)
        limit = request.max_cost_cents
        shared = _Shared(
            budget=Budget(
                float(limit if limit is not None else self._settings.max_cost_cents_default)
            ),
            shots={s.shot_id: {"state": "pending", "attempts": 0} for s in request.shots},
        )
        await ctx.update("generating", 0.02, shots=dict(shared.shots))
        results: dict[str, ClipResult] = {}

        async def one(shot: Shot) -> None:
            results[shot.shot_id] = await self._clip(request, shot, chain, shared, ctx)

        try:
            async with asyncio.TaskGroup() as group:
                for shot in request.shots:
                    group.create_task(one(shot))
        except* ServiceError as failure:
            # Report the first failure; the TaskGroup has already cancelled the other shots.
            raise failure.exceptions[0] from None
        clips = [results[s.shot_id] for s in request.shots]
        return GenerationResult(
            clips=clips,
            total_cost_cents=round(sum(c.cost_cents for c in clips), 4),
            attempted_provider_jobs=shared.provider_jobs,
        ).model_dump()

    async def _clip(
        self,
        request: GenerationRequest,
        shot: Shot,
        chain: list[str],
        shared: _Shared,
        ctx: JobContext,
    ) -> ClipResult:
        state = shared.shots[shot.shot_id]
        last: ProviderError | None = None
        attempts = 0
        for name in chain:
            provider = self.providers[name]
            if self.breakers[name].is_open:
                last = ProviderError(
                    "provider_unavailable", "Provider temporarily disabled.", retryable=True
                )
                continue
            for _ in range(self._settings.max_attempts_per_clip):
                attempts += 1
                state.update(provider=name, attempts=attempts, state="submitting")
                try:
                    clip = await self._attempt(request, shot, provider, shared, ctx, attempts)
                except ProviderRejected as error:
                    state.update(state="failed", error_code=error.code)
                    raise ServiceError(422, "provider_rejected", error.message) from error
                except ProviderError as error:
                    last = error
                    state.update(state="retrying", error_code=error.code)
                    self.breakers[name].record_failure()
                    logger.warning(
                        "clip_attempt_failed",
                        extra={
                            "shot": shot.shot_id,
                            "provider": name,
                            "code": error.code,
                            "attempt": attempts,
                        },
                    )
                    if not error.retryable:
                        break  # no point retrying this provider; try the fallback
                else:
                    self.breakers[name].record_success()
                    state.update(state="succeeded", error_code=None)
                    shared.done += 1
                    await ctx.update(
                        "generating",
                        0.05 + 0.9 * shared.done / len(request.shots),
                        shots=dict(shared.shots),
                    )
                    return clip
        state.update(state="failed")
        await ctx.update("failed", None, shots=dict(shared.shots))
        raise self._final_error(last)

    @staticmethod
    def _final_error(last: ProviderError | None) -> ServiceError:
        if last is None:
            return ServiceError(
                503, "provider_unavailable", "No video provider is available.", retryable=True
            )
        if last.code == "timeout":
            return ServiceError(504, "timeout", "The provider took too long.", retryable=True)
        if last.code == "qa_failed":
            return ServiceError(
                502,
                "qa_failed",
                "The generated clip did not pass quality checks. Try another image.",
            )
        if last.code == "provider_auth_failed":
            return ServiceError(
                502, "provider_misconfigured", "The video provider is not set up correctly."
            )
        return ServiceError(502, "provider_unavailable", last.message, retryable=last.retryable)

    async def _attempt(
        self,
        request: GenerationRequest,
        shot: Shot,
        provider: VideoProvider,
        shared: _Shared,
        ctx: JobContext,
        attempt: int,
    ) -> ClipResult:
        estimate = provider.capabilities.cost_cents_per_second * shot.ask_seconds
        shared.budget.check(estimate)
        shared.budget.spent += estimate  # reserve; corrected to the actual cost below

        async with self._storage.local_copy(shot.image_key) as image_path:
            job = await self._submit(provider, request, shot, image_path)
        shared.provider_jobs.append(f"{provider.name}:{job.id}")
        state = shared.shots[shot.shot_id]
        state.update(provider=provider.name, provider_job_id=job.id, state="running")
        await ctx.update("generating", None, shots=dict(shared.shots))
        logger.info(
            "provider_job_submitted",
            extra={"shot": shot.shot_id, "provider": provider.name, "provider_job": job.id},
        )

        try:
            with tempfile.TemporaryDirectory(prefix="clip-") as directory:
                destination = Path(directory) / f"{shot.shot_id}.mp4"
                status = await self._wait(provider, job)
                await provider.fetch(job, status, destination)
                info = await self._qa(destination, shot)
                key = f"{request.output_prefix}/{shot.shot_id}.mp4"
                await self._storage.store_file(destination, key)
        except asyncio.CancelledError:
            await asyncio.shield(self._safe_cancel(provider, job))
            raise
        except ProviderError:
            shared.budget.spent -= estimate  # a failed attempt is not charged (best effort)
            raise
        actual = status.cost_cents if status.cost_cents is not None else estimate
        shared.budget.spent += actual - estimate
        assert info.video is not None  # noqa: S101  (checked in _qa)
        return ClipResult(
            shot_id=shot.shot_id,
            storage_key=key,
            provider=provider.name,
            provider_job_id=job.id,
            attempts=attempt,
            duration_seconds=round(info.duration, 3),
            width=info.video.width,
            height=info.video.height,
            cost_cents=round(actual, 4),
        )

    async def _submit(
        self, provider: VideoProvider, request: GenerationRequest, shot: Shot, image_path: Path
    ) -> ProviderJob:
        async with self._slots:
            return await provider.submit(
                ImageToVideoRequest(
                    image_path=image_path,
                    prompt=shot.prompt,
                    negative_prompt=shot.negative_prompt,
                    duration_seconds=shot.ask_seconds,
                    aspect_ratio=request.aspect_ratio,
                    seed=request.seed,
                )
            )

    async def _wait(self, provider: VideoProvider, job: ProviderJob) -> ProviderStatus:
        """Poll with exponential backoff until the job finishes or the clip timeout passes."""
        deadline = self._clock() + self._settings.clip_timeout_seconds
        delay = self._settings.poll_initial_seconds
        while True:
            status = await provider.poll(job)
            if status.state == "succeeded":
                return status
            if status.state == "failed":
                raise status.error or ProviderError("provider_failed", "The provider failed.")
            if self._clock() + delay > deadline:
                await self._safe_cancel(provider, job)
                raise ProviderError("timeout", "The provider took too long.", retryable=True)
            await self._sleep(delay)
            delay = min(delay * 1.6, self._settings.poll_max_seconds)

    @staticmethod
    async def _safe_cancel(provider: VideoProvider, job: ProviderJob) -> None:
        try:
            await provider.cancel(job)
        except Exception:
            logger.warning(
                "provider_cancel_failed", extra={"provider": provider.name, "provider_job": job.id}
            )

    @staticmethod
    async def _qa(path: Path, shot: Shot) -> MediaInfo:
        try:
            info = await probe(path)
        except ServiceError as error:
            raise ProviderError(
                "qa_failed", "The clip could not be read.", retryable=True
            ) from error
        if info.video is None or info.video.width <= 0:
            raise ProviderError("qa_failed", "The clip has no video.", retryable=True)
        if info.duration < shot.duration_seconds - QA_DURATION_TOLERANCE:
            raise ProviderError("qa_failed", "The clip is shorter than requested.", retryable=True)
        return info
