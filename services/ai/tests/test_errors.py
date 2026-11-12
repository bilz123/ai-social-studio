from __future__ import annotations

from fastapi import FastAPI
from pydantic import BaseModel

from tests.conftest import ClientFactory


class Payload(BaseModel):
    name: str
    password: str


def _add_test_routes(app: FastAPI) -> None:
    @app.post("/_test/validate")
    async def validate(payload: Payload) -> dict[str, str]:
        return {"name": payload.name}

    @app.get("/_test/boom")
    async def boom() -> None:
        raise RuntimeError("database password is hunter2")


def test_unknown_route_returns_problem_json(make_client: ClientFactory) -> None:
    with make_client() as client:
        response = client.get("/nope")

    body = response.json()
    assert response.status_code == 404
    assert response.headers["content-type"].startswith("application/problem+json")
    assert body["code"] == "not_found"
    assert body["status"] == 404
    assert body["request_id"] == response.headers["X-Request-Id"]


def test_wrong_method_returns_405_problem(make_client: ClientFactory) -> None:
    with make_client() as client:
        response = client.post("/healthz")

    assert response.status_code == 405
    assert response.json()["code"] == "method_not_allowed"


def test_validation_error_does_not_echo_input(make_client: ClientFactory) -> None:
    with make_client(configure=_add_test_routes) as client:
        response = client.post("/_test/validate", json={"password": "hunter2"})

    body = response.json()
    assert response.status_code == 422
    assert body["code"] == "validation_failed"
    assert "body.name" in body["errors"]
    assert "hunter2" not in response.text


def test_unhandled_exception_returns_generic_500(make_client: ClientFactory) -> None:
    with make_client(configure=_add_test_routes) as client:
        response = client.get("/_test/boom")

    body = response.json()
    assert response.status_code == 500
    assert body["code"] == "internal_error"
    assert body["request_id"]
    assert "hunter2" not in response.text
