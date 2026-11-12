from __future__ import annotations

import logging
from typing import Any, cast

from fastapi import APIRouter, Request, Response
from studio_common.errors import ServiceError
from studio_common.jobs import Job, JobContext, JobManager
from studio_common.storage import Storage

from video_renderer.runner import render
from video_renderer.schemas import RenderOutput, RenderRequest, RenderView
from video_renderer.settings import VideoRendererSettings

router = APIRouter(prefix="/v1", tags=["video-renderer"])
logger = logging.getLogger(__name__)


def _view(job: Job) -> RenderView:
    return RenderView(
        id=job.id,
        status=job.status,
        stage=job.stage,
        progress=job.progress,
        result=RenderOutput(**job.result) if job.result else None,
        error=job.error,
        created_at=job.created_at.isoformat(),
        updated_at=job.updated_at.isoformat(),
    )


@router.post("/renders", response_model=RenderView, status_code=202)
async def create_render(body: RenderRequest, request: Request, response: Response) -> RenderView:
    manager = cast(JobManager, request.app.state.manager)
    storage = cast(Storage, request.app.state.storage)
    settings = cast(VideoRendererSettings, request.app.state.settings)

    if len(body.segments) > settings.max_segments:
        raise ServiceError(422, "too_many_segments", f"At most {settings.max_segments} segments.")
    keys = [s.key for s in body.segments]
    keys += [body.logo.key] if body.logo else []
    keys += [body.music.key] if body.music else []
    for key in keys:
        if not await storage.exists(key):
            raise ServiceError(404, "media_not_found", f"'{key}' does not exist.")

    async def runner(ctx: JobContext) -> dict[str, Any]:
        return await render(body, ctx, storage, settings)

    job, created = await manager.submit(runner, idempotency_key=body.idempotency_key)
    if not created:
        response.status_code = 200
    logger.info("render_accepted", extra={"job_id": job.id, "created": created})
    return _view(job)


@router.get("/renders/{render_id}", response_model=RenderView)
async def get_render(render_id: str, request: Request) -> RenderView:
    manager = cast(JobManager, request.app.state.manager)
    job = await manager.store.get(render_id)
    if job is None:
        raise ServiceError(404, "not_found", "Render not found.")
    return _view(job)


@router.post("/renders/{render_id}/cancel", response_model=RenderView)
async def cancel_render(render_id: str, request: Request) -> RenderView:
    manager = cast(JobManager, request.app.state.manager)
    job = await manager.cancel(render_id)
    if job is None:
        raise ServiceError(404, "not_found", "Render not found.")
    return _view(job)
