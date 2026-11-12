"""Dependencies and readiness checks."""

from __future__ import annotations

from typing import Annotated, Protocol

import redis.asyncio as redis_asyncio
from fastapi import Depends, Request

from app.core.config import Settings, get_settings


class ReadinessCheck(Protocol):
    name: str

    async def check(self) -> None:
        """Return normally if the dependency is usable; raise otherwise."""
        ...


class RedisCheck:
    name = "redis"

    def __init__(self, settings: Settings) -> None:
        self._url = settings.redis_url
        self._client: redis_asyncio.Redis | None = None

    def _get_client(self) -> redis_asyncio.Redis:
        if self._url is None:
            raise RuntimeError("REDIS_URL is not configured")
        if self._client is None:
            self._client = redis_asyncio.from_url(
                self._url.get_secret_value(),
                socket_connect_timeout=2,
                socket_timeout=2,
            )
        return self._client

    async def check(self) -> None:
        await self._get_client().ping()

    async def close(self) -> None:
        if self._client is not None:
            await self._client.aclose()
            self._client = None


def get_app_settings() -> Settings:
    return get_settings()


def get_readiness_checks(request: Request) -> list[ReadinessCheck]:
    checks: list[ReadinessCheck] = request.app.state.readiness_checks
    return checks


SettingsDep = Annotated[Settings, Depends(get_app_settings)]
ReadinessChecksDep = Annotated[list[ReadinessCheck], Depends(get_readiness_checks)]
