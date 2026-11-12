"""Long-running work as jobs: submit returns at once, callers poll for the result.

State lives in a `JobStore` (memory for tests and single-process use, Redis in deployment) so a
status request can be answered by any replica and survives restarts. Work itself runs inside the
process that accepted the job; a crash loses the in-flight run but never leaves a job "running"
forever, because `JobManager.recover` marks jobs a previous process left unfinished as failed.
"""

from __future__ import annotations

import asyncio
import logging
import uuid
from collections.abc import Awaitable, Callable
from datetime import UTC, datetime
from enum import StrEnum
from typing import Any, Protocol

import redis.asyncio as redis_asyncio
from pydantic import BaseModel, Field

from studio_common.errors import ServiceError

logger = logging.getLogger(__name__)


class JobStatus(StrEnum):
    QUEUED = "queued"
    RUNNING = "running"
    SUCCEEDED = "succeeded"
    FAILED = "failed"
    CANCELED = "canceled"


TERMINAL = frozenset({JobStatus.SUCCEEDED, JobStatus.FAILED, JobStatus.CANCELED})


class JobError(BaseModel):
    code: str
    message: str
    retryable: bool = False


class Job(BaseModel):
    id: str
    kind: str
    status: JobStatus = JobStatus.QUEUED
    stage: str | None = None
    progress: float = Field(default=0.0, ge=0.0, le=1.0)
    idempotency_key: str | None = None
    result: dict[str, Any] | None = None
    error: JobError | None = None
    # Free-form facts about the run (for example external provider job ids).
    details: dict[str, Any] = Field(default_factory=dict)
    created_at: datetime
    updated_at: datetime


class JobStore(Protocol):
    async def create(self, job: Job) -> Job:
        """Store a new job. If `job.idempotency_key` is already known, return that job instead."""
        ...

    async def get(self, job_id: str) -> Job | None: ...

    async def save(self, job: Job) -> None: ...

    async def unfinished(self) -> list[Job]: ...

    async def check(self) -> None: ...


class InMemoryJobStore:
    def __init__(self) -> None:
        self._jobs: dict[str, Job] = {}
        self._keys: dict[str, str] = {}

    async def create(self, job: Job) -> Job:
        if job.idempotency_key:
            existing = self._keys.get(f"{job.kind}:{job.idempotency_key}")
            if existing and existing in self._jobs:
                return self._jobs[existing]
            self._keys[f"{job.kind}:{job.idempotency_key}"] = job.id
        self._jobs[job.id] = job
        return job

    async def get(self, job_id: str) -> Job | None:
        job = self._jobs.get(job_id)
        return job.model_copy(deep=True) if job else None

    async def save(self, job: Job) -> None:
        self._jobs[job.id] = job.model_copy(deep=True)

    async def unfinished(self) -> list[Job]:
        return [j.model_copy(deep=True) for j in self._jobs.values() if j.status not in TERMINAL]

    async def check(self) -> None:
        return None


class RedisJobStore:
    def __init__(self, url: str, *, prefix: str, ttl_seconds: int) -> None:
        self._client: redis_asyncio.Redis = redis_asyncio.from_url(
            url, socket_connect_timeout=2, socket_timeout=2, decode_responses=True
        )
        self._prefix = prefix
        self._ttl = ttl_seconds

    def _job_key(self, job_id: str) -> str:
        return f"{self._prefix}:job:{job_id}"

    async def create(self, job: Job) -> Job:
        if job.idempotency_key:
            key = f"{self._prefix}:idem:{job.kind}:{job.idempotency_key}"
            if not await self._client.set(key, job.id, nx=True, ex=self._ttl):
                existing_id = await self._client.get(key)
                if existing_id:
                    existing = await self.get(str(existing_id))
                    if existing:
                        return existing
        await self._client.set(self._job_key(job.id), job.model_dump_json(), ex=self._ttl)
        await self._client.sadd(f"{self._prefix}:open", job.id)
        return job

    async def get(self, job_id: str) -> Job | None:
        raw = await self._client.get(self._job_key(job_id))
        return Job.model_validate_json(raw) if raw else None

    async def save(self, job: Job) -> None:
        await self._client.set(self._job_key(job.id), job.model_dump_json(), ex=self._ttl)
        if job.status in TERMINAL:
            await self._client.srem(f"{self._prefix}:open", job.id)

    async def unfinished(self) -> list[Job]:
        ids = await self._client.smembers(f"{self._prefix}:open")
        jobs = [await self.get(str(i)) for i in ids]
        return [j for j in jobs if j is not None and j.status not in TERMINAL]

    async def check(self) -> None:
        await self._client.ping()

    async def close(self) -> None:
        await self._client.aclose()


class JobContext:
    """Handed to a job's runner so it can report progress and notice cancellation."""

    def __init__(self, job: Job, store: JobStore) -> None:
        self.job = job
        self._store = store

    async def update(self, stage: str, progress: float | None = None, **details: Any) -> None:
        self.job.stage = stage
        if progress is not None:
            self.job.progress = max(0.0, min(1.0, progress))
        self.job.details.update(details)
        self.job.updated_at = _now()
        await self._store.save(self.job)


Runner = Callable[[JobContext], Awaitable[dict[str, Any]]]


def _now() -> datetime:
    return datetime.now(UTC)


class JobManager:
    def __init__(self, store: JobStore, *, kind: str, max_concurrent: int = 2) -> None:
        self.store = store
        self.kind = kind
        self._slots = asyncio.Semaphore(max_concurrent)
        self._tasks: dict[str, asyncio.Task[None]] = {}

    async def submit(
        self, runner: Runner, *, idempotency_key: str | None = None
    ) -> tuple[Job, bool]:
        """Create a job and start it. Returns (job, created); `created` is False for a repeat."""
        now = _now()
        job = Job(
            id=uuid.uuid4().hex,
            kind=self.kind,
            idempotency_key=idempotency_key,
            created_at=now,
            updated_at=now,
        )
        stored = await self.store.create(job)
        if stored.id != job.id:
            return stored, False
        self._tasks[job.id] = asyncio.create_task(self._run(job, runner), name=f"job-{job.id}")
        return job, True

    async def _run(self, job: Job, runner: Runner) -> None:
        context = JobContext(job, self.store)
        try:
            async with self._slots:
                job.status = JobStatus.RUNNING
                await context.update("starting", 0.0)
                job.result = await runner(context)
                job.status = JobStatus.SUCCEEDED
                job.progress = 1.0
                job.stage = "done"
        except asyncio.CancelledError:
            job.status = JobStatus.CANCELED
            job.stage = "canceled"
            job.error = JobError(code="canceled", message="The job was canceled.")
        except ServiceError as error:
            job.status = JobStatus.FAILED
            job.error = JobError(code=error.code, message=error.detail, retryable=error.retryable)
            logger.warning("job_failed", extra={"job_id": job.id, "code": error.code})
        except TimeoutError:
            job.status = JobStatus.FAILED
            job.error = JobError(code="timeout", message="The job took too long.", retryable=True)
            logger.warning("job_timeout", extra={"job_id": job.id})
        except Exception:
            job.status = JobStatus.FAILED
            job.error = JobError(code="internal_error", message="The job failed unexpectedly.")
            logger.exception("job_crashed", extra={"job_id": job.id})
        finally:
            job.updated_at = _now()
            await self.store.save(job)
            self._tasks.pop(job.id, None)

    async def cancel(self, job_id: str) -> Job | None:
        task = self._tasks.get(job_id)
        if task is not None:
            task.cancel()
            await asyncio.gather(task, return_exceptions=True)
        return await self.store.get(job_id)

    async def recover(self) -> int:
        """Mark jobs left unfinished by a previous process as failed (retryable)."""
        stale = [j for j in await self.store.unfinished() if j.id not in self._tasks]
        for job in stale:
            job.status = JobStatus.FAILED
            job.error = JobError(
                code="interrupted",
                message="The service restarted while this job was running.",
                retryable=True,
            )
            job.updated_at = _now()
            await self.store.save(job)
        return len(stale)

    async def shutdown(self) -> None:
        tasks = list(self._tasks.values())
        for task in tasks:
            task.cancel()
        await asyncio.gather(*tasks, return_exceptions=True)


class StoreCheck:
    name = "job_store"

    def __init__(self, store: JobStore) -> None:
        self._store = store

    async def check(self) -> None:
        await self._store.check()


def build_job_store(url: str | None, *, prefix: str, ttl_seconds: int) -> JobStore:
    if url:
        return RedisJobStore(url, prefix=prefix, ttl_seconds=ttl_seconds)
    return InMemoryJobStore()
