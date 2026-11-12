from __future__ import annotations

import logging
from typing import Any, cast

from fastapi import APIRouter, Request, Response
from studio_common.errors import ServiceError
from studio_common.jobs import Job, JobContext, JobManager
from studio_common.storage import Storage

from video_generator.engine import GenerationEngine
from video_generator.schemas import (
    GenerationRequest,
    GenerationResult,
    GenerationView,
    ProviderInfo,
    ShotProgress,
)
from video_generator.settings import VideoGeneratorSettings

router = APIRouter(prefix="/v1", tags=["video-generator"])
logger = logging.getLogger(__name__)


def _view(job: Job) -> GenerationView:
    shots = {k: ShotProgress(**v) for k, v in job.details.get("shots", {}).items()}
    return GenerationView(
        id=job.id,
        status=job.status,
        stage=job.stage,
        progress=job.progress,
        shots=shots,
        result=GenerationResult(**job.result) if job.result else None,
        error=job.error,
        created_at=job.created_at.isoformat(),
        updated_at=job.updated_at.isoformat(),
    )


@router.get("/providers", response_model=list[ProviderInfo])
async def providers(request: Request) -> list[ProviderInfo]:
    engine = cast(GenerationEngine, request.app.state.engine)
    settings = cast(VideoGeneratorSettings, request.app.state.settings)
    out: list[ProviderInfo] = []
    for name, provider in engine.providers.items():
        role = (
            "primary"
            if name == settings.video_provider_primary
            else "fallback"
            if name == settings.video_provider_fallback
            else "available"
        )
        caps = provider.capabilities
        out.append(
            ProviderInfo(
                name=name,
                max_seconds=caps.max_seconds,
                aspect_ratios=list(caps.aspect_ratios),
                cost_cents_per_second=caps.cost_cents_per_second,
                role=role,
            )
        )
    return out


@router.post("/generations", response_model=GenerationView, status_code=202)
async def create_generation(
    body: GenerationRequest, request: Request, response: Response
) -> GenerationView:
    engine = cast(GenerationEngine, request.app.state.engine)
    manager = cast(JobManager, request.app.state.manager)
    storage = cast(Storage, request.app.state.storage)

    chain = engine.chain(body.provider)
    if not chain:
        raise ServiceError(422, "unknown_provider", "That video provider is not configured.")
    caps = engine.providers[chain[0]].capabilities
    if body.aspect_ratio not in caps.aspect_ratios:
        raise ServiceError(422, "unsupported_aspect_ratio", "The provider cannot make that shape.")
    for shot in body.shots:
        if shot.ask_seconds > caps.max_seconds:
            raise ServiceError(
                422,
                "unsupported_duration",
                f"Shot '{shot.shot_id}' needs {shot.ask_seconds:g}s but the provider allows "
                f"{caps.max_seconds:g}s. Split the video into more shots.",
            )
        if not await storage.exists(shot.image_key):
            raise ServiceError(
                404, "media_not_found", f"Image for shot '{shot.shot_id}' not found."
            )

    async def runner(ctx: JobContext) -> dict[str, Any]:
        return await engine.run(body, ctx)

    job, created = await manager.submit(runner, idempotency_key=body.idempotency_key)
    if not created:
        response.status_code = 200
    logger.info(
        "generation_accepted",
        extra={"job_id": job.id, "shots": len(body.shots), "created": created},
    )
    return _view(job)


@router.get("/generations/{generation_id}", response_model=GenerationView)
async def get_generation(generation_id: str, request: Request) -> GenerationView:
    manager = cast(JobManager, request.app.state.manager)
    job = await manager.store.get(generation_id)
    if job is None:
        raise ServiceError(404, "not_found", "Generation not found.")
    return _view(job)


@router.post("/generations/{generation_id}/cancel", response_model=GenerationView)
async def cancel_generation(generation_id: str, request: Request) -> GenerationView:
    manager = cast(JobManager, request.app.state.manager)
    job = await manager.cancel(generation_id)
    if job is None:
        raise ServiceError(404, "not_found", "Generation not found.")
    return _view(job)
