from __future__ import annotations

import asyncio
import os
from collections.abc import Callable, Iterator
from contextlib import AbstractContextManager, contextmanager

# Must be set before `app.main` is imported (it builds an app at import time).
os.environ.setdefault("AI_ENVIRONMENT", "testing")

import pytest
from fastapi import FastAPI
from fastapi.testclient import TestClient

from app.api.deps import ReadinessCheck
from app.core.config import Settings
from app.main import create_app


class StubCheck:
    """Readiness check double: succeeds, fails, or hangs on demand."""

    def __init__(self, name: str = "stub", error: Exception | None = None, delay: float = 0.0):
        self.name = name
        self._error = error
        self._delay = delay

    async def check(self) -> None:
        if self._delay:
            await asyncio.sleep(self._delay)
        if self._error is not None:
            raise self._error


ClientFactory = Callable[..., AbstractContextManager[TestClient]]


@pytest.fixture
def settings() -> Settings:
    return Settings(environment="testing", redis_url=None, readiness_timeout_seconds=0.2)


@pytest.fixture
def make_client(settings: Settings) -> ClientFactory:
    @contextmanager
    def factory(
        checks: list[ReadinessCheck] | None = None,
        configure: Callable[[FastAPI], None] | None = None,
    ) -> Iterator[TestClient]:
        app = create_app(settings)
        if configure is not None:
            configure(app)
        with TestClient(app, raise_server_exceptions=False) as client:
            if checks is not None:
                app.state.readiness_checks = checks
            yield client

    return factory
