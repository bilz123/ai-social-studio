from __future__ import annotations

import logging
from typing import Any, cast

from fastapi import APIRouter, Request, Response
from studio_common.errors import ServiceError
from studio_common.jobs import Job, JobContext, JobManager
from studio_common.media import probe
from studio_common.storage import Storage

from video_optimizer.runner import optimize
from video_optimizer.schemas import (
    OptimizeRequest,
    OptimizeResult,
    OptimizeView,
    ValidateRequest,
    ValidationReport,
)
from video_optimizer.settings import VideoOptimizerSettings
from video_optimizer.validation import deep_checks, facts_from, validate_facts

router = APIRouter(prefix="/v1", tags=["video-optimizer"])
logger = logging.getLogger(__name__)


def _view(job: Job) -> OptimizeView:
    return OptimizeView(
        id=job.id,
        status=job.status,
        stage=job.stage,
        progress=job.progress,
        result=OptimizeResult(**job.result) if job.result else None,
        error=job.error,
        created_at=job.created_at.isoformat(),
        updated_at=job.updated_at.isoformat(),
    )


@router.post("/validate", response_model=ValidationReport)
async def validate(body: ValidateRequest, request: Request) -> ValidationReport:
    storage = cast(Storage, request.app.state.storage)
    settings = cast(VideoOptimizerSettings, request.app.state.settings)
    if not await storage.exists(body.video_key):
        raise ServiceError(404, "media_not_found", f"'{body.video_key}' does not exist.")
    async with storage.local_copy(body.video_key) as path:
        info = await probe(path)
        report = validate_facts(
            facts_from(info, path),
            body.profile,
            expected_duration=body.expected_duration_seconds,
            max_mb=settings.max_output_mb,
        )
        if body.deep and info.video is not None:
            report.checks.extend(await deep_checks(path, info.duration))
    return report


@router.post("/optimizations", response_model=OptimizeView, status_code=202)
async def create_optimization(
    body: OptimizeRequest, request: Request, response: Response
) -> OptimizeView:
    manager = cast(JobManager, request.app.state.manager)
    storage = cast(Storage, request.app.state.storage)
    settings = cast(VideoOptimizerSettings, request.app.state.settings)
    if not await storage.exists(body.video_key):
        raise ServiceError(404, "media_not_found", f"'{body.video_key}' does not exist.")

    async def runner(ctx: JobContext) -> dict[str, Any]:
        return await optimize(body, ctx, storage, settings)

    job, created = await manager.submit(runner, idempotency_key=body.idempotency_key)
    if not created:
        response.status_code = 200
    logger.info("optimization_accepted", extra={"job_id": job.id, "created": created})
    return _view(job)


@router.get("/optimizations/{job_id}", response_model=OptimizeView)
async def get_optimization(job_id: str, request: Request) -> OptimizeView:
    job = await cast(JobManager, request.app.state.manager).store.get(job_id)
    if job is None:
        raise ServiceError(404, "not_found", "Optimization not found.")
    return _view(job)


@router.post("/optimizations/{job_id}/cancel", response_model=OptimizeView)
async def cancel_optimization(job_id: str, request: Request) -> OptimizeView:
    job = await cast(JobManager, request.app.state.manager).cancel(job_id)
    if job is None:
        raise ServiceError(404, "not_found", "Optimization not found.")
    return _view(job)
