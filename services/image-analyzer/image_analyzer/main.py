"""Image Analyzer FastAPI application.

Run with: uvicorn --factory image_analyzer.main:create_app
"""

from __future__ import annotations

from collections.abc import AsyncIterator
from contextlib import asynccontextmanager

from fastapi import FastAPI
from studio_common.app_factory import create_service_app
from studio_common.health import ReadinessCheck
from studio_common.storage import Storage, StorageCheck, build_storage

from image_analyzer import __version__
from image_analyzer.routes import router
from image_analyzer.settings import ImageAnalyzerSettings
from image_analyzer.vision import OpenAICompatVision


def create_app(
    settings: ImageAnalyzerSettings | None = None,
    *,
    storage: Storage | None = None,
    vision: object | None = None,
) -> FastAPI:
    settings = settings or ImageAnalyzerSettings()

    @asynccontextmanager
    async def hook(app: FastAPI) -> AsyncIterator[list[ReadinessCheck]]:
        app.state.storage = storage or build_storage(settings)
        app.state.vision = vision or (
            OpenAICompatVision(settings) if settings.vision_enabled else None
        )
        yield [StorageCheck(app.state.storage)]

    app: FastAPI = create_service_app(
        settings=settings,
        title="AI Social Studio - Image Analyzer",
        version=__version__,
        routers=[router],
        lifespan_hook=hook,
    )
    return app
