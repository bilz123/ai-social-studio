"""Real FFmpeg runs on small synthetic videos, checked with ffprobe and by looking at pixels."""

from __future__ import annotations

import time
from pathlib import Path
from typing import Any

import pytest
from fastapi.testclient import TestClient
from studio_common.media import probe
from studio_common.storage import LocalStorage

from tests.helpers import ffmpeg, make_clip
from video_optimizer.geometry import bitrate_for, plan_fit
from video_optimizer.main import create_app
from video_optimizer.schemas import OptimizeRequest
from video_optimizer.settings import VideoOptimizerSettings


def make_master(
    path: Path, seconds: float = 4.0, size: str = "540x960", audio: bool = True
) -> Path:
    path.parent.mkdir(parents=True, exist_ok=True)
    args = ["-f", "lavfi", "-i", f"testsrc2=size={size}:rate=30:duration={seconds}"]
    if audio:
        args += ["-f", "lavfi", "-i", f"sine=frequency=440:sample_rate=48000:duration={seconds}"]
    args += ["-c:v", "libx264", "-pix_fmt", "yuv420p", "-profile:v", "high"]
    if audio:
        args += ["-c:a", "aac", "-ac", "2", "-ar", "48000"]
    args += ["-movflags", "+faststart", str(path)]
    ffmpeg(*args)
    return path


@pytest.fixture
def media(tmp_path: Path) -> Path:
    root = tmp_path / "media"
    make_master(root / "in/master.mp4")
    return root


def client_for(media: Path, **settings: Any) -> TestClient:
    cfg = VideoOptimizerSettings(environment="testing", **settings)
    return TestClient(create_app(cfg, storage=LocalStorage(media)))


def run(client: TestClient, payload: dict[str, Any]) -> dict[str, Any]:
    response = client.post("/v1/optimizations", json=payload)
    assert response.status_code == 202, response.text
    job_id = response.json()["id"]
    end = time.time() + 120
    while time.time() < end:
        data: dict[str, Any] = client.get(f"/v1/optimizations/{job_id}").json()
        if data["status"] in ("succeeded", "failed", "canceled"):
            return data
        time.sleep(0.1)
    raise AssertionError("optimization did not finish")


def payload(**over: Any) -> dict[str, Any]:
    base: dict[str, Any] = {
        "video_key": "in/master.mp4",
        "output_prefix": "out/v1",
        "outputs": [{"name": "reels", "aspect_ratio": "9:16", "preset": "veryfast"}],
    }
    base.update(over)
    return base


# ---- pure helpers -------------------------------------------------------------------------


def test_plan_fit_crop_and_pad() -> None:
    crop = plan_fit(1920, 1080, "9:16", "crop", 0.5)
    assert (crop.width, crop.height) == (606, 1080)
    assert crop.filter.startswith("crop=606:1080")
    wide = plan_fit(1080, 1920, "16:9", "crop", 0.5)
    assert (wide.width, wide.height) == (1080, 606)
    pad = plan_fit(1920, 1080, "9:16", "pad_blur", 0.5)
    assert pad.width % 2 == 0 and pad.height % 2 == 0
    assert pad.width / pad.height == pytest.approx(9 / 16, abs=0.01)
    assert "overlay" in pad.filter and "boxblur" in pad.filter


def test_bitrate_target_math() -> None:
    bps = bitrate_for(5 * 1024 * 1024, 10, 128_000)
    assert 3_000_000 < bps < 4_200_000
    assert bitrate_for(1000, 10, 128_000) == 150_000


def test_request_rejects_duplicates_and_bad_keys() -> None:
    with pytest.raises(ValueError, match="unique"):
        OptimizeRequest(
            video_key="a.mp4",
            output_prefix="o",
            outputs=[{"name": "x"}, {"name": "x"}],
        )
    with pytest.raises(ValueError):
        OptimizeRequest(video_key="../a.mp4", output_prefix="o", outputs=[{"name": "x"}])


# ---- validate -----------------------------------------------------------------------------


def test_validate_good_master(media: Path) -> None:
    with client_for(media) as client:
        report = client.post(
            "/v1/validate",
            json={"video_key": "in/master.mp4", "expected_duration_seconds": 4.0, "deep": True},
        ).json()
    assert report["valid"], [c for c in report["checks"] if not c["passed"]]
    names = {c["name"] for c in report["checks"]}
    assert {"video_codec", "faststart", "frozen_frames", "black_frames"} <= names
    assert report["facts"]["width"] == 540 and report["facts"]["faststart"] is True


def test_validate_reports_each_problem(media: Path) -> None:
    ffmpeg("-f", "lavfi", "-i", "testsrc2=size=640x360:rate=15:duration=2", "-an",
           "-c:v", "libx264", "-pix_fmt", "yuv444p", str(media / "in/bad.mp4"))  # fmt: skip
    with client_for(media) as client:
        report = client.post("/v1/validate", json={"video_key": "in/bad.mp4"}).json()
    failed = {c["name"] for c in report["checks"] if not c["passed"]}
    assert not report["valid"]
    assert {"pixel_format", "audio_codec", "faststart", "frame_rate", "aspect_ratio"} <= failed


def test_validate_flags_wrong_duration_and_missing_file(media: Path) -> None:
    with client_for(media) as client:
        report = client.post(
            "/v1/validate", json={"video_key": "in/master.mp4", "expected_duration_seconds": 10}
        ).json()
        missing = client.post("/v1/validate", json={"video_key": "in/none.mp4"})
    assert not report["valid"]
    assert next(c for c in report["checks"] if c["name"] == "expected_duration")["passed"] is False
    assert missing.status_code == 404 and missing.json()["code"] == "media_not_found"


def test_validate_rejects_non_video(media: Path) -> None:
    (media / "in/notes.mp4").write_bytes(b"not a video at all")
    with client_for(media) as client:
        response = client.post("/v1/validate", json={"video_key": "in/notes.mp4"})
    assert response.status_code in (415, 422)


def test_faststart_detects_missing_faststart(media: Path) -> None:
    from video_optimizer.validation import faststart

    plain = media / "in/plain.mp4"
    ffmpeg("-f", "lavfi", "-i", "testsrc2=size=320x240:rate=30:duration=1", "-c:v", "libx264",
           "-pix_fmt", "yuv420p", str(plain))  # fmt: skip
    assert faststart(plain) is False
    assert faststart(media / "in/master.mp4") is True


# ---- optimise -----------------------------------------------------------------------------


async def _info(media: Path, key: str) -> Any:
    return await probe(media / key)


def test_optimize_exports_validate_and_thumbnails(media: Path) -> None:
    with client_for(media) as client:
        data = run(
            client,
            payload(
                outputs=[
                    {"name": "reels", "aspect_ratio": "9:16", "preset": "veryfast",
                     "profile": "instagram_reels"},
                    {"name": "square", "aspect_ratio": "1:1", "preset": "veryfast"},
                    {"name": "wide", "aspect_ratio": "16:9", "mode": "pad_blur",
                     "preset": "veryfast"},
                ],
                thumbnails={"items": [{"name": "cover", "aspect_ratio": "9:16", "width": 540},
                                      {"name": "yt", "aspect_ratio": "16:9", "width": 640}]},
            ),
        )  # fmt: skip
    assert data["status"] == "succeeded", data["error"]
    outs = {o["name"]: o for o in data["result"]["outputs"]}
    assert (outs["reels"]["width"], outs["reels"]["height"]) == (540, 960)
    assert outs["reels"]["validation"]["valid"] is True
    assert outs["square"]["width"] == outs["square"]["height"]
    assert outs["wide"]["width"] / outs["wide"]["height"] == pytest.approx(16 / 9, abs=0.02)
    for out in outs.values():
        assert (media / out["storage_key"]).is_file()
    thumbs = {t["name"]: t for t in data["result"]["thumbnails"]}
    assert (thumbs["cover"]["width"], thumbs["cover"]["height"]) == (540, 960)
    assert (thumbs["yt"]["width"], thumbs["yt"]["height"]) == (640, 360)
    assert (media / thumbs["cover"]["storage_key"]).read_bytes()[:2] == b"\xff\xd8"
    assert 0 < thumbs["cover"]["at_seconds"] < 4


def test_output_is_real_spec_h264(media: Path) -> None:
    import asyncio

    with client_for(media) as client:
        data = run(client, payload())
    info = asyncio.run(probe(media / data["result"]["outputs"][0]["storage_key"]))
    assert info.video and info.video.codec == "h264" and info.video.pix_fmt == "yuv420p"
    assert info.audio and info.audio.codec == "aac" and info.audio.sample_rate == 48000
    assert abs(info.duration - 4.0) < 0.15


def test_size_target_retries_until_under_limit(tmp_path: Path) -> None:
    root = tmp_path / "media"
    # Noisy content so the first CRF encode is comfortably large.
    (root / "in").mkdir(parents=True)
    ffmpeg("-f", "lavfi", "-i", "testsrc2=size=360x640:rate=30:duration=4",
           "-vf", "noise=alls=40:allf=t", "-pix_fmt", "yuv420p", "-c:v", "libx264", "-crf", "10",
           "-an", str(root / "in/noisy.mp4"))  # fmt: skip
    with client_for(root) as client:
        data = run(
            client,
            payload(
                video_key="in/noisy.mp4",
                outputs=[{"name": "small", "aspect_ratio": "9:16", "crf": 18,
                          "preset": "veryfast", "max_size_mb": 0.3}],
            ),
        )  # fmt: skip
    assert data["status"] == "succeeded", data["error"]
    out = data["result"]["outputs"][0]
    assert out["size_bytes"] <= 0.3 * 1024 * 1024
    assert out["attempts"] >= 2
    assert out["size_bytes"] < out["original_size_bytes"]


def test_unreachable_size_target_fails_cleanly(tmp_path: Path) -> None:
    media = tmp_path / "media"
    (media / "in").mkdir(parents=True)
    ffmpeg("-f", "lavfi", "-i", "testsrc2=size=360x640:rate=30:duration=12",
           "-f", "lavfi", "-i", "sine=frequency=440:sample_rate=48000:duration=12",
           "-c:v", "libx264", "-preset", "ultrafast", "-pix_fmt", "yuv420p", "-c:a", "aac",
           str(media / "in/master.mp4"))  # fmt: skip
    with client_for(media) as client:
        spec = {"name": "tiny", "aspect_ratio": "9:16", "preset": "veryfast", "max_size_mb": 0.21}
        data = run(client, payload(outputs=[spec]))
    assert data["status"] == "failed"
    assert data["error"]["code"] in ("size_target_unreachable", "output_invalid")


def test_source_without_audio_gets_silent_aac_track(tmp_path: Path) -> None:
    root = tmp_path / "media"
    make_master(root / "in/silent.mp4", audio=False)
    import asyncio

    with client_for(root) as client:
        data = run(client, payload(video_key="in/silent.mp4"))
    assert data["status"] == "succeeded", data["error"]
    info = asyncio.run(probe(root / data["result"]["outputs"][0]["storage_key"]))
    assert info.audio is not None and info.audio.channels == 2


def test_profile_mismatch_fails_with_output_invalid(media: Path) -> None:
    with client_for(media) as client:
        spec = {
            "name": "sq",
            "aspect_ratio": "1:1",
            "preset": "veryfast",
            "profile": "youtube_shorts",
        }
        data = run(client, payload(outputs=[spec]))
    assert data["status"] == "failed" and data["error"]["code"] == "output_invalid"
    assert "aspect_ratio" in data["error"]["message"]
    assert not (media / "out/v1/sq.mp4").exists()


def test_rejects_non_video_input_and_missing_input(media: Path) -> None:
    make_clip(media / "in/ok.mp4", 1.0)
    (media / "in/fake.mp4").write_bytes(b"x" * 2000)
    with client_for(media) as client:
        missing = client.post("/v1/optimizations", json=payload(video_key="in/none.mp4"))
        data = run(client, payload(video_key="in/fake.mp4"))
    assert missing.status_code == 404
    assert data["status"] == "failed"
    assert data["error"]["code"] in ("unsupported_input", "invalid_media")


def test_idempotency_and_cancel_unknown(media: Path) -> None:
    body = payload(idempotency_key="same-1")
    with client_for(media) as client:
        first = client.post("/v1/optimizations", json=body)
        second = client.post("/v1/optimizations", json=body)
        assert (first.status_code, second.status_code) == (202, 200)
        assert first.json()["id"] == second.json()["id"]
        run(client, payload(idempotency_key="other"))
        assert client.post("/v1/optimizations/nope/cancel").status_code == 404
        assert client.get("/v1/optimizations/nope").status_code == 404


def test_extra_fields_rejected(media: Path) -> None:
    with client_for(media) as client:
        response = client.post("/v1/optimizations", json=payload(surprise=1))
    assert response.status_code == 422


def test_health_endpoints(media: Path) -> None:
    with client_for(media) as client:
        assert client.get("/healthz").json()["service"] == "video-optimizer"
        ready = client.get("/readyz")
    assert ready.status_code == 200
    assert set(ready.json()["checks"]) == {"storage", "job_store", "ffmpeg"}
