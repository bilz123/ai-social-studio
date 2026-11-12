from __future__ import annotations

import hashlib
import json
from pathlib import Path
from typing import Any

import httpx
import pytest
from fastapi.testclient import TestClient
from pydantic import SecretStr
from studio_common.storage import LocalStorage

from image_analyzer.main import create_app
from image_analyzer.settings import ImageAnalyzerSettings
from image_analyzer.vision import OpenAICompatVision
from tests.helpers import blurred, product_on_white


@pytest.fixture
def media(tmp_path: Path) -> Path:
    root = tmp_path / "media"
    (root / "u1").mkdir(parents=True)
    (root / "u1" / "good.jpg").write_bytes(product_on_white())
    (root / "u1" / "blur.jpg").write_bytes(blurred())
    (root / "u1" / "junk.jpg").write_bytes(b"nope")
    return root


def client_for(media: Path, **kwargs: Any) -> TestClient:
    settings = ImageAnalyzerSettings(environment="testing", **kwargs.pop("settings", {}))
    return TestClient(create_app(settings, storage=LocalStorage(media), **kwargs))


def test_analyze_returns_per_image_data_and_preservation_rules(media: Path) -> None:
    body = {
        "images": [
            {"media_id": "a", "storage_key": "u1/blur.jpg"},
            {"media_id": "b", "storage_key": "u1/good.jpg"},
        ],
        "brief": {"title": "Blue leather backpack", "description": "A durable leather backpack."},
    }
    with client_for(media) as client:
        response = client.post("/v1/analyze", json=body)
    assert response.status_code == 200, response.text
    data = response.json()
    assert [i["media_id"] for i in data["images"]] == ["a", "b"]
    assert data["preservation"]["hero_media_id"] == "b"
    assert data["preservation"]["original_modified"] is False
    assert "warped logo" in data["preservation"]["negative_prompt_terms"]
    assert data["product"]["category"] == "bag"
    assert data["product"]["source"] == "heuristic"
    assert "leather" in data["product"]["materials"]
    assert "vision_not_configured" in data["warnings"]


def test_originals_are_left_untouched(media: Path) -> None:
    path = media / "u1" / "good.jpg"
    before = (hashlib.sha256(path.read_bytes()).hexdigest(), path.stat().st_mtime_ns)
    with client_for(media) as client:
        client.post(
            "/v1/analyze", json={"images": [{"media_id": "a", "storage_key": "u1/good.jpg"}]}
        )
    assert (hashlib.sha256(path.read_bytes()).hexdigest(), path.stat().st_mtime_ns) == before
    assert sorted(p.name for p in path.parent.iterdir()) == ["blur.jpg", "good.jpg", "junk.jpg"]


def test_sha256_matches_the_stored_file(media: Path) -> None:
    expected = hashlib.sha256((media / "u1" / "good.jpg").read_bytes()).hexdigest()
    with client_for(media) as client:
        data = client.post(
            "/v1/analyze", json={"images": [{"media_id": "a", "storage_key": "u1/good.jpg"}]}
        ).json()
    assert data["images"][0]["sha256"] == expected


@pytest.mark.parametrize(
    ("body", "status", "code"),
    [
        ({"images": [{"media_id": "a", "storage_key": "u1/junk.jpg"}]}, 422, "image_corrupt"),
        ({"images": [{"media_id": "a", "storage_key": "u1/missing.jpg"}]}, 404, "media_not_found"),
        (
            {"images": [{"media_id": "a", "storage_key": "../etc/passwd"}]},
            422,
            "invalid_storage_key",
        ),
        (
            {"images": [{"media_id": "a", "storage_key": "u1/good.jpg"}] * 2},
            422,
            "duplicate_media_id",
        ),
    ],
)
def test_errors_are_problem_json_with_codes(
    media: Path, body: dict[str, Any], status: int, code: str
) -> None:
    with client_for(media) as client:
        response = client.post("/v1/analyze", json=body)
    assert response.status_code == status
    assert response.json()["code"] == code
    assert response.headers["content-type"].startswith("application/problem+json")


def test_request_validation_rejects_unknown_fields_and_empty_lists(media: Path) -> None:
    with client_for(media) as client:
        assert client.post("/v1/analyze", json={"images": []}).status_code == 422
        bad = {"images": [{"media_id": "a", "storage_key": "k", "url": "http://x"}]}
        assert client.post("/v1/analyze", json=bad).status_code == 422


def test_too_many_images_are_refused(media: Path) -> None:
    refs = [{"media_id": f"m{i}", "storage_key": "u1/good.jpg"} for i in range(3)]
    with client_for(media, settings={"max_images": 2}) as client:
        response = client.post("/v1/analyze", json={"images": refs})
    assert response.status_code == 422
    assert response.json()["code"] == "too_many_images"


def vision_settings() -> ImageAnalyzerSettings:
    return ImageAnalyzerSettings(
        environment="testing",
        vision_base_url="https://llm.example.test/v1",
        vision_model="vision-model",
        vision_api_key=SecretStr("test-key"),
    )


def test_vision_result_is_used_and_credentials_are_sent_only_as_a_header(media: Path) -> None:
    seen: dict[str, Any] = {}

    def handler(request: httpx.Request) -> httpx.Response:
        seen["auth"] = request.headers["authorization"]
        seen["body"] = json.loads(request.content)
        answer = {
            "category": "bag",
            "name": "Blue backpack",
            "materials": ["canvas"],
            "colors": ["blue"],
            "key_features": ["padded straps"],
            "has_logo": False,
            "has_people": False,
            "confidence": 0.9,
        }
        return httpx.Response(200, json={"choices": [{"message": {"content": json.dumps(answer)}}]})

    settings = vision_settings()
    vision = OpenAICompatVision(settings, httpx.AsyncClient(transport=httpx.MockTransport(handler)))
    app = create_app(settings, storage=LocalStorage(media), vision=vision)
    with TestClient(app) as client:
        data = client.post(
            "/v1/analyze", json={"images": [{"media_id": "a", "storage_key": "u1/good.jpg"}]}
        ).json()
    assert data["product"]["source"] == "vision"
    assert data["product"]["key_features"] == ["padded straps"]
    assert seen["auth"] == "Bearer test-key"
    assert "test-key" not in json.dumps(seen["body"])
    assert seen["body"]["messages"][1]["content"][1]["image_url"]["url"].startswith(
        "data:image/jpeg"
    )


@pytest.mark.parametrize(
    "response",
    [
        httpx.Response(500, text="boom"),
        httpx.Response(200, json={"choices": [{"message": {"content": "not json"}}]}),
        httpx.Response(200, json={"unexpected": True}),
    ],
)
def test_vision_failures_fall_back_to_measurements(media: Path, response: httpx.Response) -> None:
    settings = vision_settings()
    transport = httpx.MockTransport(lambda request: response)
    vision = OpenAICompatVision(settings, httpx.AsyncClient(transport=transport))
    app = create_app(settings, storage=LocalStorage(media), vision=vision)
    with TestClient(app) as client:
        result = client.post(
            "/v1/analyze", json={"images": [{"media_id": "a", "storage_key": "u1/good.jpg"}]}
        )
    assert result.status_code == 200
    assert result.json()["product"]["source"] == "heuristic"
    assert "vision_unavailable" in result.json()["warnings"]


def test_model_output_is_clamped(media: Path) -> None:
    answer = {"category": "x" * 500, "key_features": ["f" * 1000] * 20}
    transport = httpx.MockTransport(
        lambda r: httpx.Response(
            200, json={"choices": [{"message": {"content": json.dumps(answer)}}]}
        )
    )
    settings = vision_settings()
    vision = OpenAICompatVision(settings, httpx.AsyncClient(transport=transport))
    app = create_app(settings, storage=LocalStorage(media), vision=vision)
    with TestClient(app) as client:
        result = client.post(
            "/v1/analyze", json={"images": [{"media_id": "a", "storage_key": "u1/good.jpg"}]}
        )
    # category is over its length limit, so the whole answer is rejected rather than truncated.
    assert result.json()["product"]["source"] == "heuristic"


def test_health_and_readiness(media: Path) -> None:
    with client_for(media) as client:
        assert client.get("/healthz").json()["service"] == "image-analyzer"
        ready = client.get("/readyz")
    assert ready.status_code == 200
    assert ready.json()["checks"]["storage"]["status"] == "ok"
