"""One place that builds a service's FastAPI app so every service behaves the same way."""

from __future__ import annotations

from collections.abc import AsyncIterator, Callable
from contextlib import AbstractAsyncContextManager, asynccontextmanager

from fastapi import APIRouter, FastAPI

from studio_common import __version__
from studio_common.config import ServiceSettings
from studio_common.errors import register_exception_handlers
from studio_common.health import ReadinessCheck, build_health_router
from studio_common.logging import configure_logging
from studio_common.middleware import RequestContextMiddleware
from studio_common.security import SecurityMiddleware

Hook = Callable[[FastAPI], AbstractAsyncContextManager[list[ReadinessCheck]]]


def create_service_app(
    *,
    settings: ServiceSettings,
    title: str,
    version: str,
    routers: list[APIRouter],
    lifespan_hook: Hook | None = None,
) -> FastAPI:
    """Build an app with request ids, JSON logs, HMAC auth, problem+json errors and health checks.

    `lifespan_hook(app)` is an async context manager that sets up the service's resources (it may
    store them on `app.state`) and yields its readiness checks.
    """
    if settings.environment != "testing":
        configure_logging(settings.log_level, settings.service_name)

    @asynccontextmanager
    async def lifespan(app: FastAPI) -> AsyncIterator[None]:
        app.state.settings = settings
        app.state.readiness_checks = []
        if lifespan_hook is None:
            yield
            return
        async with lifespan_hook(app) as checks:
            app.state.readiness_checks = checks
            yield

    docs = settings.docs_enabled
    app = FastAPI(
        title=title,
        version=version,
        docs_url="/docs" if docs else None,
        redoc_url=None,
        openapi_url="/openapi.json" if docs else None,
        lifespan=lifespan,
    )
    secret = (
        settings.hmac_secret_laravel_to_ai.get_secret_value()
        if settings.hmac_secret_laravel_to_ai
        else None
    )
    # Added first = innermost: request ids and logging wrap everything, including auth rejections.
    app.add_middleware(
        SecurityMiddleware,
        secret=secret or None,
        max_body_bytes=settings.max_body_bytes,
        max_skew_seconds=settings.hmac_max_skew_seconds,
    )
    app.add_middleware(RequestContextMiddleware)
    register_exception_handlers(app)
    app.include_router(
        build_health_router(settings.service_name, version, settings.readiness_timeout_seconds)
    )
    for router in routers:
        app.include_router(router)
    app.state.common_version = __version__
    return app
