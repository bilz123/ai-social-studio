"""Liveness (`/healthz`) and readiness (`/readyz`) endpoints.

Responses never include exception text or connection strings; failures are logged instead.
"""

from __future__ import annotations

import asyncio
import logging
import time
from typing import Literal, Protocol

from fastapi import APIRouter, Request
from fastapi.responses import JSONResponse
from pydantic import BaseModel

from studio_common import __version__

logger = logging.getLogger(__name__)


class ReadinessCheck(Protocol):
    name: str

    async def check(self) -> None:
        """Return normally if the dependency is usable; raise otherwise."""
        ...


class LiveResponse(BaseModel):
    status: Literal["ok"] = "ok"
    service: str
    version: str


class CheckResult(BaseModel):
    status: Literal["ok", "fail"]
    latency_ms: float | None = None


class ReadyResponse(BaseModel):
    status: Literal["ok", "degraded"]
    checks: dict[str, CheckResult]


def build_health_router(service_name: str, version: str, timeout: float) -> APIRouter:
    router = APIRouter(tags=["health"])

    @router.get("/healthz", response_model=LiveResponse)
    async def healthz() -> LiveResponse:
        return LiveResponse(service=service_name, version=version)

    @router.get("/readyz", response_model=ReadyResponse, responses={503: {"model": ReadyResponse}})
    async def readyz(request: Request) -> JSONResponse:
        checks: list[ReadinessCheck] = request.app.state.readiness_checks
        results: dict[str, CheckResult] = {}
        for check in checks:
            started = time.perf_counter()
            try:
                await asyncio.wait_for(check.check(), timeout=timeout)
            except Exception:
                logger.exception("readiness_check_failed", extra={"check": check.name})
                results[check.name] = CheckResult(status="fail")
            else:
                latency = round((time.perf_counter() - started) * 1000, 2)
                results[check.name] = CheckResult(status="ok", latency_ms=latency)
        healthy = all(r.status == "ok" for r in results.values())
        body = ReadyResponse(status="ok" if healthy else "degraded", checks=results)
        return JSONResponse(body.model_dump(exclude_none=True), status_code=200 if healthy else 503)

    return router


__all__ = [
    "CheckResult",
    "LiveResponse",
    "ReadinessCheck",
    "ReadyResponse",
    "__version__",
    "build_health_router",
]
