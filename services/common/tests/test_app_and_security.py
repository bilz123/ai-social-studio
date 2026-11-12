from __future__ import annotations

import time
from collections.abc import AsyncIterator
from contextlib import asynccontextmanager

from fastapi import APIRouter, FastAPI
from fastapi.testclient import TestClient
from pydantic import BaseModel, SecretStr

from studio_common.app_factory import create_service_app
from studio_common.config import ServiceSettings
from studio_common.errors import ServiceError
from studio_common.health import ReadinessCheck
from studio_common.security import sign

SECRET = "unit-test-secret"


class Body(BaseModel):
    n: int


router = APIRouter()


@router.post("/echo")
async def echo(body: Body) -> dict[str, int]:
    return {"n": body.n}


@router.get("/boom")
async def boom() -> None:
    raise ServiceError(409, "custom_code", "Nope.", retryable=False)


@router.get("/crash")
async def crash() -> None:
    raise RuntimeError("database password is hunter2")


class Failing:
    name = "dep"

    async def check(self) -> None:
        raise RuntimeError("connection refused to db://user:pw@host")


def make(secret: str | None = SECRET, checks: list[ReadinessCheck] | None = None) -> FastAPI:
    settings = ServiceSettings(
        environment="testing",
        service_name="t",
        hmac_secret_laravel_to_ai=SecretStr(secret) if secret else None,
        max_body_bytes=200,
    )

    @asynccontextmanager
    async def hook(app: FastAPI) -> AsyncIterator[list[ReadinessCheck]]:
        yield checks or []

    return create_service_app(
        settings=settings, title="t", version="1", routers=[router], lifespan_hook=hook
    )


def signed(method: str, path: str, body: bytes = b"", *, offset: int = 0) -> dict[str, str]:
    ts = int(time.time()) + offset
    return {
        "X-Timestamp": str(ts),
        "X-Signature": sign(SECRET, timestamp=ts, method=method, path=path, body=body),
        "Content-Type": "application/json",
    }


def test_health_endpoints_need_no_signature() -> None:
    with TestClient(make()) as client:
        assert client.get("/healthz").json() == {"status": "ok", "service": "t", "version": "1"}
        assert client.get("/readyz").json()["status"] == "ok"


def test_readiness_failure_hides_details() -> None:
    with TestClient(make(checks=[Failing()])) as client:
        response = client.get("/readyz")
    assert response.status_code == 503
    assert response.json() == {"status": "degraded", "checks": {"dep": {"status": "fail"}}}


def test_unsigned_requests_are_rejected() -> None:
    with TestClient(make()) as client:
        response = client.post("/echo", json={"n": 1})
    assert response.status_code == 401
    assert response.headers["content-type"].startswith("application/problem+json")
    assert response.headers["x-request-id"]


def test_valid_signature_is_accepted() -> None:
    body = b'{"n": 3}'
    with TestClient(make()) as client:
        response = client.post("/echo", content=body, headers=signed("POST", "/echo", body))
    assert response.status_code == 200
    assert response.json() == {"n": 3}


def test_tampered_body_stale_timestamp_and_wrong_path_are_rejected() -> None:
    body = b'{"n": 3}'
    with TestClient(make()) as client:
        tampered = client.post("/echo", content=b'{"n": 4}', headers=signed("POST", "/echo", body))
        stale = client.post(
            "/echo", content=body, headers=signed("POST", "/echo", body, offset=-3600)
        )
        wrong_path = client.post("/echo", content=body, headers=signed("POST", "/other", body))
    assert tampered.status_code == 401
    assert stale.status_code == 401
    assert wrong_path.status_code == 401


def test_get_requests_need_a_signature_too() -> None:
    with TestClient(make()) as client:
        assert client.get("/boom").status_code == 401
        assert client.get("/boom", headers=signed("GET", "/boom")).status_code == 409


def test_oversized_bodies_are_rejected() -> None:
    body = b'{"n": 1, "pad": "' + b"x" * 500 + b'"}'
    with TestClient(make()) as client:
        response = client.post("/echo", content=body, headers=signed("POST", "/echo", body))
    assert response.status_code == 413


def test_service_errors_carry_a_stable_code() -> None:
    with TestClient(make(secret=None)) as client:
        response = client.get("/boom")
    assert response.status_code == 409
    assert response.json()["code"] == "custom_code"
    assert response.json()["retryable"] is False


def test_unhandled_errors_are_generic() -> None:
    with TestClient(make(secret=None), raise_server_exceptions=False) as client:
        response = client.get("/crash")
    assert response.status_code == 500
    assert "hunter2" not in response.text


def test_validation_errors_do_not_echo_input() -> None:
    with TestClient(make(secret=None)) as client:
        response = client.post("/echo", json={"n": "super-secret"})
    assert response.status_code == 422
    assert "super-secret" not in response.text
