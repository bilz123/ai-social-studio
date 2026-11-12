"""Video Generator FastAPI application.

Run with: uvicorn --factory video_generator.main:create_app
"""

from __future__ import annotations

from collections.abc import AsyncIterator
from contextlib import asynccontextmanager

from fastapi import FastAPI
from studio_common.app_factory import create_service_app
from studio_common.health import ReadinessCheck
from studio_common.jobs import JobManager, JobStore, RedisJobStore, StoreCheck, build_job_store
from studio_common.media import FFmpegCheck
from studio_common.storage import Storage, StorageCheck, build_storage

from video_generator import __version__
from video_generator.engine import GenerationEngine
from video_generator.providers.base import VideoProvider
from video_generator.registry import build_providers
from video_generator.routes import router
from video_generator.settings import VideoGeneratorSettings


def create_app(
    settings: VideoGeneratorSettings | None = None,
    *,
    storage: Storage | None = None,
    providers: dict[str, VideoProvider] | None = None,
    store: JobStore | None = None,
    engine: GenerationEngine | None = None,
) -> FastAPI:
    settings = settings or VideoGeneratorSettings()

    @asynccontextmanager
    async def hook(app: FastAPI) -> AsyncIterator[list[ReadinessCheck]]:
        app.state.storage = storage or build_storage(settings)
        job_store = store or build_job_store(
            settings.redis_url.get_secret_value() if settings.redis_url else None,
            prefix="video-generator",
            ttl_seconds=settings.job_ttl_seconds,
        )
        app.state.engine = engine or GenerationEngine(
            providers or build_providers(settings), settings, app.state.storage
        )
        manager = JobManager(
            job_store, kind="generation", max_concurrent=settings.max_concurrent_jobs
        )
        app.state.manager = manager
        await manager.recover()
        try:
            yield [StorageCheck(app.state.storage), StoreCheck(job_store), FFmpegCheck()]
        finally:
            await manager.shutdown()
            if isinstance(job_store, RedisJobStore):
                await job_store.close()

    app: FastAPI = create_service_app(
        settings=settings,
        title="AI Social Studio - Video Generator",
        version=__version__,
        routers=[router],
        lifespan_hook=hook,
    )
    return app
