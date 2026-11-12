"""Description Analyzer FastAPI application.

Run with: uvicorn --factory description_analyzer.main:create_app
"""

from __future__ import annotations

from collections.abc import AsyncIterator
from contextlib import asynccontextmanager

from fastapi import FastAPI
from studio_common.app_factory import create_service_app
from studio_common.health import ReadinessCheck

from description_analyzer import __version__
from description_analyzer.llm import InsightsProvider, OpenAICompatLLM
from description_analyzer.routes import router
from description_analyzer.settings import DescriptionAnalyzerSettings


def create_app(
    settings: DescriptionAnalyzerSettings | None = None,
    *,
    llm: InsightsProvider | None = None,
) -> FastAPI:
    settings = settings or DescriptionAnalyzerSettings()

    @asynccontextmanager
    async def hook(app: FastAPI) -> AsyncIterator[list[ReadinessCheck]]:
        app.state.llm = llm or (OpenAICompatLLM(settings) if settings.llm_enabled else None)
        yield []

    app: FastAPI = create_service_app(
        settings=settings,
        title="AI Social Studio - Description Analyzer",
        version=__version__,
        routers=[router],
        lifespan_hook=hook,
    )
    return app
