"""Video Renderer FastAPI application.

Run with: uvicorn --factory video_renderer.main:create_app
"""

from __future__ import annotations

from collections.abc import AsyncIterator
from contextlib import asynccontextmanager
from pathlib import Path

from fastapi import FastAPI
from studio_common.app_factory import create_service_app
from studio_common.health import ReadinessCheck
from studio_common.jobs import JobManager, JobStore, RedisJobStore, StoreCheck, build_job_store
from studio_common.media import FFmpegCheck
from studio_common.storage import Storage, StorageCheck, build_storage

from video_renderer import __version__
from video_renderer.routes import router
from video_renderer.settings import VideoRendererSettings


class FontCheck:
    name = "font"

    def __init__(self, path: str) -> None:
        self._path = path

    async def check(self) -> None:
        if not Path(self._path).is_file():
            raise RuntimeError("The configured font file is missing")


def create_app(
    settings: VideoRendererSettings | None = None,
    *,
    storage: Storage | None = None,
    store: JobStore | None = None,
) -> FastAPI:
    settings = settings or VideoRendererSettings()

    @asynccontextmanager
    async def hook(app: FastAPI) -> AsyncIterator[list[ReadinessCheck]]:
        app.state.storage = storage or build_storage(settings)
        job_store = store or build_job_store(
            settings.redis_url.get_secret_value() if settings.redis_url else None,
            prefix="video-renderer",
            ttl_seconds=settings.job_ttl_seconds,
        )
        manager = JobManager(job_store, kind="render", max_concurrent=settings.max_concurrent_jobs)
        app.state.manager = manager
        await manager.recover()
        try:
            yield [
                StorageCheck(app.state.storage),
                StoreCheck(job_store),
                FFmpegCheck(),
                FontCheck(settings.font_path),
            ]
        finally:
            await manager.shutdown()
            if isinstance(job_store, RedisJobStore):
                await job_store.close()

    app: FastAPI = create_service_app(
        settings=settings,
        title="AI Social Studio - Video Renderer",
        version=__version__,
        routers=[router],
        lifespan_hook=hook,
    )
    return app
