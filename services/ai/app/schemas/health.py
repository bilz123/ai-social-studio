from __future__ import annotations

from typing import Literal

from pydantic import BaseModel


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
