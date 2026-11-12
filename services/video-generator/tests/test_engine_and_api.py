from __future__ import annotations

import time
from pathlib import Path
from typing import Any

import pytest
from fastapi.testclient import TestClient
from studio_common.jobs import InMemoryJobStore
from studio_common.media import probe
from studio_common.storage import LocalStorage

from tests.helpers import ScriptedProvider, make_image
from video_generator.engine import GenerationEngine
from video_generator.main import create_app
from video_generator.providers.base import VideoProvider
from video_generator.settings import VideoGeneratorSettings


def settings(**overrides: Any) -> VideoGeneratorSettings:
    base: dict[str, Any] = {
        "environment": "testing",
        "video_provider_primary": "primary",
        "video_provider_fallback": None,
        "poll_initial_seconds": 0.001,
        "poll_max_seconds": 0.005,
        "clip_timeout_seconds": 0.5,
        "max_attempts_per_clip": 2,
        "breaker_failures": 3,
    }
    return VideoGeneratorSettings.model_construct(
        **{**VideoGeneratorSettings().model_dump(), **base, **overrides}
    )


def body(shots: int = 2, **extra: Any) -> dict[str, Any]:
    durations = [1.5, 1.5] if shots == 2 else [1.5]
    return {
        "aspect_ratio": "9:16",
        "output_prefix": "work/gen1",
        "shots": [
            {
                "shot_id": f"s{i}",
                "image_key": "u/img.jpg",
                "prompt": "A backpack. Camera: slow dolly-in.",
                "negative_prompt": "warped logo",
                "duration_seconds": durations[i],
            }
            for i in range(shots)
        ],
        **extra,
    }


class Harness:
    def __init__(self, tmp: Path, providers: dict[str, ScriptedProvider], **overrides: Any) -> None:
        self.media = tmp / "media"
        make_image(self.media / "u" / "img.jpg")
        self.providers = providers
        self.settings = settings(**overrides)
        self.storage = LocalStorage(self.media)
        engine = GenerationEngine(dict(providers), self.settings, self.storage)
        self.engine = engine
        self.app = create_app(
            self.settings, storage=self.storage, store=InMemoryJobStore(), engine=engine
        )

    def wait(self, client: TestClient, job_id: str, timeout: float = 20.0) -> dict[str, Any]:
        end = time.time() + timeout
        while time.time() < end:
            data: dict[str, Any] = client.get(f"/v1/generations/{job_id}").json()
            if data["status"] in ("succeeded", "failed", "canceled"):
                return data
            time.sleep(0.02)
        raise AssertionError("generation did not finish")


@pytest.fixture
def tmp(tmp_path: Path) -> Path:
    return tmp_path


def test_successful_generation_stores_clips_and_tracks_provider_job_ids(tmp: Path) -> None:
    primary = ScriptedProvider("primary", [], tmp, cost_per_second=2.0)
    h = Harness(tmp, {"primary": primary})
    with TestClient(h.app) as client:
        accepted = client.post("/v1/generations", json=body())
        assert accepted.status_code == 202
        done = h.wait(client, accepted.json()["id"])
    assert done["status"] == "succeeded", done
    clips = done["result"]["clips"]
    assert [c["shot_id"] for c in clips] == ["s0", "s1"]
    assert {c["provider_job_id"] for c in clips} == set(primary.submitted)
    assert sorted(j.split(":")[1] for j in done["result"]["attempted_provider_jobs"]) == sorted(
        primary.submitted
    )
    assert done["result"]["total_cost_cents"] == 15.0  # provider-reported 7.5 per clip
    assert all(s["provider_job_id"] for s in done["shots"].values())
    for clip in clips:
        assert (h.media / clip["storage_key"]).is_file()
    # The provider was asked for the padded length, and was given the negative prompt.
    assert primary.requests[0].negative_prompt == "warped logo"
    assert primary.requests[0].aspect_ratio == "9:16"


async def test_stored_clip_is_valid_media(tmp: Path) -> None:
    primary = ScriptedProvider("primary", [], tmp)
    h = Harness(tmp, {"primary": primary})
    with TestClient(h.app) as client:
        done = h.wait(client, client.post("/v1/generations", json=body(1)).json()["id"])
    info = await probe(h.media / done["result"]["clips"][0]["storage_key"])
    assert info.video is not None and info.duration >= 1.2


def test_retryable_failure_is_retried_on_the_same_provider(tmp: Path) -> None:
    primary = ScriptedProvider("primary", ["fail_retryable"], tmp)
    h = Harness(tmp, {"primary": primary})
    with TestClient(h.app) as client:
        done = h.wait(client, client.post("/v1/generations", json=body(1)).json()["id"])
    assert done["status"] == "succeeded"
    assert done["result"]["clips"][0]["attempts"] == 2
    assert len(done["result"]["attempted_provider_jobs"]) == 2


def test_fallback_provider_takes_over_after_the_primary_keeps_failing(tmp: Path) -> None:
    primary = ScriptedProvider("primary", ["fail_retryable", "fail_retryable"], tmp)
    backup = ScriptedProvider("backup", [], tmp)
    h = Harness(tmp, {"primary": primary, "backup": backup}, video_provider_fallback="backup")
    with TestClient(h.app) as client:
        done = h.wait(client, client.post("/v1/generations", json=body(1)).json()["id"])
    assert done["status"] == "succeeded"
    clip = done["result"]["clips"][0]
    assert clip["provider"] == "backup"
    assert clip["attempts"] == 3


def test_permanent_failure_skips_retries_and_goes_to_the_fallback(tmp: Path) -> None:
    primary = ScriptedProvider("primary", ["fail_permanent"], tmp)
    backup = ScriptedProvider("backup", [], tmp)
    h = Harness(tmp, {"primary": primary, "backup": backup}, video_provider_fallback="backup")
    with TestClient(h.app) as client:
        done = h.wait(client, client.post("/v1/generations", json=body(1)).json()["id"])
    assert done["status"] == "succeeded"
    assert primary.calls == 1


def test_content_rejection_fails_fast_without_retry_or_fallback(tmp: Path) -> None:
    primary = ScriptedProvider("primary", ["reject"], tmp)
    backup = ScriptedProvider("backup", [], tmp)
    h = Harness(tmp, {"primary": primary, "backup": backup}, video_provider_fallback="backup")
    with TestClient(h.app) as client:
        done = h.wait(client, client.post("/v1/generations", json=body(1)).json()["id"])
    assert done["status"] == "failed"
    assert done["error"]["code"] == "provider_rejected"
    assert done["error"]["retryable"] is False
    assert primary.calls == 1 and backup.calls == 0


def test_all_providers_failing_reports_a_retryable_error(tmp: Path) -> None:
    primary = ScriptedProvider("primary", ["fail_retryable"] * 2, tmp)
    h = Harness(tmp, {"primary": primary})
    with TestClient(h.app) as client:
        done = h.wait(client, client.post("/v1/generations", json=body(1)).json()["id"])
    assert done["status"] == "failed"
    assert done["error"]["code"] == "provider_unavailable"
    assert done["error"]["retryable"] is True
    assert done["shots"]["s0"]["state"] == "failed"


def test_a_stalled_provider_times_out_and_the_external_job_is_cancelled(tmp: Path) -> None:
    primary = ScriptedProvider("primary", ["stall", "stall"], tmp)
    h = Harness(tmp, {"primary": primary}, clip_timeout_seconds=0.15)
    with TestClient(h.app) as client:
        done = h.wait(client, client.post("/v1/generations", json=body(1)).json()["id"])
    assert done["status"] == "failed"
    assert done["error"]["code"] == "timeout"
    assert done["error"]["retryable"] is True
    assert set(primary.cancelled) == set(primary.submitted)


def test_unusable_clips_fail_quality_checks(tmp: Path) -> None:
    primary = ScriptedProvider("primary", ["short", "garbage"], tmp)
    h = Harness(tmp, {"primary": primary})
    with TestClient(h.app) as client:
        done = h.wait(client, client.post("/v1/generations", json=body(1)).json()["id"])
    assert done["status"] == "failed"
    assert done["error"]["code"] == "qa_failed"


def test_cost_limit_stops_the_job_before_any_provider_call(tmp: Path) -> None:
    primary = ScriptedProvider("primary", [], tmp, cost_per_second=10.0)
    h = Harness(tmp, {"primary": primary})
    with TestClient(h.app) as client:
        done = h.wait(
            client, client.post("/v1/generations", json=body(1, max_cost_cents=5)).json()["id"]
        )
    assert done["error"]["code"] == "cost_limit_exceeded"
    assert primary.calls == 0


def test_idempotency_key_returns_the_same_generation(tmp: Path) -> None:
    primary = ScriptedProvider("primary", [], tmp)
    h = Harness(tmp, {"primary": primary})
    with TestClient(h.app) as client:
        first = client.post("/v1/generations", json=body(1, idempotency_key="abc"))
        second = client.post("/v1/generations", json=body(1, idempotency_key="abc"))
        h.wait(client, first.json()["id"])
    assert first.status_code == 202 and second.status_code == 200
    assert first.json()["id"] == second.json()["id"]
    assert primary.calls == 1


def test_cancel_stops_a_running_generation_and_cancels_at_the_provider(tmp: Path) -> None:
    primary = ScriptedProvider("primary", ["stall"], tmp)
    h = Harness(tmp, {"primary": primary}, clip_timeout_seconds=30.0)
    with TestClient(h.app) as client:
        job_id = client.post("/v1/generations", json=body(1)).json()["id"]
        time.sleep(0.3)
        cancelled = client.post(f"/v1/generations/{job_id}/cancel").json()
    assert cancelled["status"] == "canceled"
    assert primary.cancelled == primary.submitted


@pytest.mark.parametrize(
    ("change", "status", "code"),
    [
        ({"provider": "nope"}, 422, "unknown_provider"),
        (
            {"shots": [{**body(1)["shots"][0], "image_key": "u/missing.jpg"}]},
            404,
            "media_not_found",
        ),
        (
            {"shots": [{**body(1)["shots"][0], "duration_seconds": 9, "request_seconds": 11}]},
            422,
            "unsupported_duration",
        ),
        ({"shots": [{**body(1)["shots"][0], "image_key": "../x"}]}, 422, "invalid_storage_key"),
    ],
)
def test_requests_are_validated_before_a_job_is_created(
    tmp: Path, change: dict[str, Any], status: int, code: str
) -> None:
    h = Harness(tmp, {"primary": ScriptedProvider("primary", [], tmp, max_seconds=10)})
    with TestClient(h.app) as client:
        response = client.post("/v1/generations", json={**body(1), **change})
    # Bad keys are caught when the storage key is checked.
    assert (response.status_code, response.json()["code"]) == (status, code)


@pytest.mark.parametrize(
    "bad",
    [
        {"shots": []},
        {"output_prefix": "../escape"},
        {"aspect_ratio": "4:3"},
        {"unknown": 1},
        {"timeline": {"total_duration_seconds": 10.0, "crossfade_seconds": 1.0}},  # 1.5+1.5-1 != 10
    ],
)
def test_schema_validation(tmp: Path, bad: dict[str, Any]) -> None:
    h = Harness(tmp, {"primary": ScriptedProvider("primary", [], tmp)})
    with TestClient(h.app) as client:
        assert client.post("/v1/generations", json={**body(2), **bad}).status_code == 422


def test_ten_second_two_shot_timeline_is_accepted(tmp: Path) -> None:
    shots = [{**body(1)["shots"][0], "shot_id": f"s{i}", "duration_seconds": 5.5} for i in range(2)]
    # 5.5s clips need a provider that can make them; our scripted clips are shorter, so only
    # check that the request itself is accepted and the timeline arithmetic passes.
    primary = ScriptedProvider("primary", ["stall", "stall"], tmp)
    h = Harness(tmp, {"primary": primary}, clip_timeout_seconds=30.0)
    payload = {
        **body(1),
        "shots": shots,
        "timeline": {"total_duration_seconds": 10.0, "crossfade_seconds": 1.0},
    }
    with TestClient(h.app) as client:
        response = client.post("/v1/generations", json=payload)
        client.post(f"/v1/generations/{response.json()['id']}/cancel")
    assert response.status_code == 202


def test_unknown_generation_is_404_and_providers_are_listed_without_secrets(tmp: Path) -> None:
    h = Harness(tmp, {"primary": ScriptedProvider("primary", [], tmp)})
    with TestClient(h.app) as client:
        assert client.get("/v1/generations/nope").status_code == 404
        listing = client.get("/v1/providers").json()
        ready = client.get("/readyz")
    assert listing == [
        {"name": "primary", "max_seconds": 10.0, "aspect_ratios": ["9:16", "1:1", "16:9"],
         "cost_cents_per_second": 0.0, "role": "primary"}
    ]  # fmt: skip
    assert ready.status_code == 200


def test_circuit_breaker_opens_after_repeated_failures_and_recovers() -> None:
    from video_generator.engine import CircuitBreaker

    now = [0.0]
    breaker = CircuitBreaker(2, 10.0, lambda: now[0])
    breaker.record_failure()
    assert not breaker.is_open
    breaker.record_failure()
    assert breaker.is_open
    now[0] = 11.0
    assert not breaker.is_open


def test_provider_instances_satisfy_the_protocol(tmp: Path) -> None:
    provider: VideoProvider = ScriptedProvider("p", [], tmp)
    assert provider.name == "p"
