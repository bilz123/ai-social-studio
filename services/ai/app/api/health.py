"""Liveness (`/healthz`) and readiness (`/readyz`) endpoints.

Liveness says "the process is up". Readiness says "my dependencies work". Responses never
include exception text or connection strings; failures are logged server-side instead.
"""

from __future__ import annotations

import asyncio
import logging
import time

from fastapi import APIRouter
from fastapi.responses import JSONResponse

from app import __version__
from app.api.deps import ReadinessChecksDep, SettingsDep
from app.schemas.health import CheckResult, LiveResponse, ReadyResponse

router = APIRouter(tags=["health"])
logger = logging.getLogger(__name__)


@router.get("/healthz", response_model=LiveResponse)
async def healthz(settings: SettingsDep) -> LiveResponse:
    return LiveResponse(service=settings.service_name, version=__version__)


@router.get("/readyz", response_model=ReadyResponse, responses={503: {"model": ReadyResponse}})
async def readyz(settings: SettingsDep, checks: ReadinessChecksDep) -> JSONResponse:
    results: dict[str, CheckResult] = {}
    for check in checks:
        started = time.perf_counter()
        try:
            await asyncio.wait_for(check.check(), timeout=settings.readiness_timeout_seconds)
        except Exception:
            logger.exception("readiness_check_failed", extra={"check": check.name})
            results[check.name] = CheckResult(status="fail")
        else:
            latency = round((time.perf_counter() - started) * 1000, 2)
            results[check.name] = CheckResult(status="ok", latency_ms=latency)

    healthy = all(result.status == "ok" for result in results.values())
    body = ReadyResponse(status="ok" if healthy else "degraded", checks=results)
    return JSONResponse(body.model_dump(exclude_none=True), status_code=200 if healthy else 503)
