"""Runs against a real Redis when REDIS_URL is set (CI service container / local `make test-ai`)."""

from __future__ import annotations

import os

import pytest
from fastapi.testclient import TestClient

from app.core.config import Settings
from app.main import create_app

pytestmark = pytest.mark.integration

REDIS_URL = os.environ.get("REDIS_URL")


@pytest.mark.skipif(not REDIS_URL, reason="REDIS_URL not set")
def test_readyz_ok_against_real_redis() -> None:
    settings = Settings(environment="testing", redis_url=REDIS_URL)
    with TestClient(create_app(settings)) as client:
        response = client.get("/readyz")

    assert response.status_code == 200
    assert response.json()["checks"]["redis"]["status"] == "ok"


def test_readyz_503_when_redis_unreachable() -> None:
    # Port 1 is closed: the connection is refused immediately.
    settings = Settings(
        environment="testing",
        redis_url="redis://127.0.0.1:1/0",
        readiness_timeout_seconds=1.0,
    )
    with TestClient(create_app(settings)) as client:
        response = client.get("/readyz")

    assert response.status_code == 503
    assert response.json()["checks"]["redis"]["status"] == "fail"
