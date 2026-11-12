from __future__ import annotations

from app import __version__
from tests.conftest import ClientFactory, StubCheck


def test_healthz_reports_live(make_client: ClientFactory) -> None:
    with make_client() as client:
        response = client.get("/healthz")

    assert response.status_code == 200
    assert response.json() == {"status": "ok", "service": "ai-api", "version": __version__}


def test_readyz_ok_when_all_checks_pass(make_client: ClientFactory) -> None:
    with make_client(checks=[StubCheck("redis")]) as client:
        response = client.get("/readyz")

    body = response.json()
    assert response.status_code == 200
    assert body["status"] == "ok"
    assert body["checks"]["redis"]["status"] == "ok"
    assert body["checks"]["redis"]["latency_ms"] >= 0


def test_readyz_503_and_no_error_details_when_a_check_fails(make_client: ClientFactory) -> None:
    secret_error = RuntimeError("could not connect to redis://:supersecret@redis:6379/2")
    with make_client(checks=[StubCheck("redis", error=secret_error)]) as client:
        response = client.get("/readyz")

    assert response.status_code == 503
    assert response.json() == {"status": "degraded", "checks": {"redis": {"status": "fail"}}}
    assert "supersecret" not in response.text


def test_readyz_503_when_a_check_times_out(make_client: ClientFactory) -> None:
    with make_client(checks=[StubCheck("redis", delay=5.0)]) as client:
        response = client.get("/readyz")

    assert response.status_code == 503
    assert response.json()["checks"]["redis"]["status"] == "fail"


def test_readyz_503_when_redis_is_not_configured(make_client: ClientFactory) -> None:
    # No stub injected: the real RedisCheck runs with redis_url=None.
    with make_client() as client:
        response = client.get("/readyz")

    assert response.status_code == 503
    assert response.json()["checks"]["redis"]["status"] == "fail"


def test_request_id_is_generated(make_client: ClientFactory) -> None:
    with make_client() as client:
        response = client.get("/healthz")

    assert len(response.headers["X-Request-Id"]) >= 8


def test_valid_incoming_request_id_is_propagated(make_client: ClientFactory) -> None:
    with make_client() as client:
        response = client.get("/healthz", headers={"X-Request-Id": "abc-12345678"})

    assert response.headers["X-Request-Id"] == "abc-12345678"


def test_invalid_incoming_request_id_is_replaced(make_client: ClientFactory) -> None:
    with make_client() as client:
        response = client.get("/healthz", headers={"X-Request-Id": "bad id with spaces!"})

    assert response.headers["X-Request-Id"] != "bad id with spaces!"
