from __future__ import annotations

import json
from pathlib import Path
from typing import Any

import httpx
import pytest
from fastapi.testclient import TestClient
from pydantic import SecretStr
from studio_common.media import probe
from studio_common.storage import LocalStorage

from tests.helpers import make_image
from video_generator.main import create_app
from video_generator.providers.base import ImageToVideoRequest, ProviderJob, ProviderStatus
from video_generator.providers.fake import FakeProvider
from video_generator.providers.http import HttpProvider, download_url_allowed
from video_generator.settings import VideoGeneratorSettings


def http_settings(**extra: Any) -> VideoGeneratorSettings:
    return VideoGeneratorSettings(
        environment="testing",
        video_provider_primary="http",
        video_http_base_url="https://video.example.test",
        video_http_api_key=SecretStr("k-123"),
        **extra,
    )


def request(tmp: Path) -> ImageToVideoRequest:
    return ImageToVideoRequest(make_image(tmp / "i.jpg"), "p", "n", 5.5, "9:16", seed=3)


async def test_http_provider_full_cycle_sends_the_key_only_in_a_header(tmp_path: Path) -> None:
    seen: list[httpx.Request] = []

    def handler(req: httpx.Request) -> httpx.Response:
        seen.append(req)
        if req.method == "POST":
            return httpx.Response(200, json={"id": "ext-1"})
        if req.url.path == "/jobs/ext-1":
            return httpx.Response(
                200,
                json={
                    "status": "succeeded",
                    "output_url": "https://cdn.example.test/v.mp4",
                    "cost_cents": 12,
                },
            )
        return httpx.Response(200, content=b"VIDEO")

    provider = HttpProvider(
        http_settings(), httpx.AsyncClient(transport=httpx.MockTransport(handler))
    )
    job = await provider.submit(request(tmp_path))
    assert job.id == "ext-1"
    status = await provider.poll(job)
    assert status.state == "succeeded" and status.cost_cents == 12
    out = tmp_path / "v.mp4"
    await provider.fetch(job, status, out)
    assert out.read_bytes() == b"VIDEO"
    submit = json.loads(seen[0].content)
    assert submit["prompt"] == "p" and submit["duration_seconds"] == 5.5 and submit["seed"] == 3
    assert all(r.headers["authorization"] == "Bearer k-123" for r in seen[:2])
    assert "k-123" not in seen[0].content.decode()


@pytest.mark.parametrize(
    ("status", "code", "retryable"),
    [
        (401, "provider_auth_failed", False),
        (422, "provider_invalid_request", False),
        (429, "provider_unavailable", True),
        (503, "provider_unavailable", True),
        (451, "provider_rejected", False),
    ],
)
async def test_http_errors_are_classified(
    tmp_path: Path, status: int, code: str, retryable: bool
) -> None:
    from video_generator.providers.base import ProviderError

    transport = httpx.MockTransport(lambda r: httpx.Response(status, text="secret body"))
    provider = HttpProvider(http_settings(), httpx.AsyncClient(transport=transport))
    with pytest.raises(ProviderError) as caught:
        await provider.submit(request(tmp_path))
    assert (caught.value.code, caught.value.retryable) == (code, retryable)
    assert "secret body" not in caught.value.message


async def test_http_poll_maps_failures_and_policy_rejections() -> None:
    answers = iter(
        [
            {"status": "failed", "error": {"code": "gpu_oom", "message": "oom", "retryable": True}},
            {"status": "failed", "error": {"code": "content_policy", "message": "no"}},
            {"status": "exploded"},
        ]
    )
    transport = httpx.MockTransport(lambda r: httpx.Response(200, json=next(answers)))
    provider = HttpProvider(http_settings(), httpx.AsyncClient(transport=transport))
    job = ProviderJob("x", "http")
    first = await provider.poll(job)
    assert first.error is not None and first.error.retryable and first.error.code == "gpu_oom"
    second = await provider.poll(job)
    assert second.error is not None and second.error.code == "provider_rejected"
    from video_generator.providers.base import ProviderError

    with pytest.raises(ProviderError):
        await provider.poll(job)


@pytest.mark.parametrize(
    "url",
    [
        "http://cdn.example.test/v.mp4",
        "https://127.0.0.1/v.mp4",
        "https://10.0.0.5/v.mp4",
        "https://169.254.169.254/latest/meta-data",
        "https://localhost/v.mp4",
        "https://db.internal/v.mp4",
        "file:///etc/passwd",
        "",
    ],
)
def test_download_urls_that_could_reach_internal_systems_are_refused(url: str) -> None:
    assert not download_url_allowed(url, set())


def test_download_allowlist() -> None:
    assert download_url_allowed("https://cdn.example.test/v.mp4", set())
    assert download_url_allowed("https://cdn.example.test/v.mp4", {"cdn.example.test"})
    assert not download_url_allowed("https://other.test/v.mp4", {"cdn.example.test"})


async def test_blocked_download_url_is_refused(tmp_path: Path) -> None:
    from video_generator.providers.base import ProviderError

    provider = HttpProvider(
        http_settings(),
        httpx.AsyncClient(transport=httpx.MockTransport(lambda r: httpx.Response(200))),
    )
    status = ProviderStatus(state="succeeded", output_url="https://169.254.169.254/x")
    with pytest.raises(ProviderError):
        await provider.fetch(ProviderJob("x", "http"), status, tmp_path / "o.mp4")


async def test_oversized_downloads_are_cut_off(tmp_path: Path) -> None:
    from video_generator.providers.base import ProviderError

    transport = httpx.MockTransport(lambda r: httpx.Response(200, content=b"x" * 5000))
    provider = HttpProvider(
        http_settings(max_clip_bytes=1000), httpx.AsyncClient(transport=transport)
    )
    status = ProviderStatus(state="succeeded", output_url="https://cdn.example.test/v.mp4")
    with pytest.raises(ProviderError):
        await provider.fetch(ProviderJob("x", "http"), status, tmp_path / "o.mp4")


async def test_fake_provider_renders_a_real_clip_with_the_requested_length(tmp_path: Path) -> None:
    fake = FakeProvider(tmp_path / "w")
    (tmp_path / "w").mkdir()
    req = ImageToVideoRequest(make_image(tmp_path / "i.jpg"), "p", "n", 2.0, "9:16")
    job = await fake.submit(req)
    status = ProviderStatus(state="running")
    for _ in range(200):
        status = await fake.poll(job)
        if status.state != "running":
            break
        import asyncio

        await asyncio.sleep(0.05)
    assert status.state == "succeeded"
    out = tmp_path / "o.mp4"
    await fake.fetch(job, status, out)
    info = await probe(out)
    assert info.video is not None and (info.video.width, info.video.height) == (360, 640)
    assert 1.9 <= info.duration <= 2.2


def test_end_to_end_with_the_fake_provider(tmp_path: Path) -> None:
    media = tmp_path / "media"
    make_image(media / "u" / "img.jpg")
    cfg = VideoGeneratorSettings(environment="testing", video_provider_primary="fake")
    app = create_app(cfg, storage=LocalStorage(media))
    payload = {
        "output_prefix": "work/e2e",
        "shots": [
            {"shot_id": "a", "image_key": "u/img.jpg", "prompt": "p", "duration_seconds": 1.0},
            {"shot_id": "b", "image_key": "u/img.jpg", "prompt": "p", "duration_seconds": 1.0},
        ],
    }
    import time

    with TestClient(app) as client:
        job_id = client.post("/v1/generations", json=payload).json()["id"]
        data: dict[str, Any] = {}
        for _ in range(300):
            data = client.get(f"/v1/generations/{job_id}").json()
            if data["status"] in ("succeeded", "failed"):
                break
            time.sleep(0.1)
    assert data["status"] == "succeeded", data
    assert (media / "work/e2e/a.mp4").is_file() and (media / "work/e2e/b.mp4").is_file()


def test_production_refuses_the_fake_provider_and_missing_credentials() -> None:
    with pytest.raises(ValueError, match="fake"):
        VideoGeneratorSettings(
            environment="production",
            video_provider_primary="fake",
            hmac_secret_laravel_to_ai=SecretStr("s"),
        )
    with pytest.raises(ValueError, match="VIDEO_HTTP"):
        VideoGeneratorSettings(environment="local", video_provider_primary="http")
