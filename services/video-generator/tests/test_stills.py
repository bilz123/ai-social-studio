"""The free `stills` provider: real FFmpeg, checked with ffprobe and by comparing pixels."""

from __future__ import annotations

import asyncio
import subprocess
import time
from pathlib import Path

import numpy as np
import pytest
from fastapi.testclient import TestClient
from PIL import Image
from studio_common.media import probe
from studio_common.storage import LocalStorage

from video_generator.main import create_app
from video_generator.providers.base import ImageToVideoRequest, ProviderJob
from video_generator.providers.stills import (
    StillsProvider,
    build_filter,
    choose_move,
    output_size,
)
from video_generator.settings import VideoGeneratorSettings


def photo(path: Path, size: tuple[int, int] = (900, 1200)) -> Path:
    """A product-like photo: coloured grid with a distinct box in the middle."""
    path.parent.mkdir(parents=True, exist_ok=True)
    image = Image.new("RGB", size, (235, 235, 235))
    for x in range(0, size[0], 100):
        for y in range(0, size[1], 100):
            if (x // 100 + y // 100) % 2:
                image.paste((200, 215, 235), (x, y, x + 100, y + 100))
    cx, cy = size[0] // 2, size[1] // 2
    image.paste((200, 30, 30), (cx - 150, cy - 250, cx + 150, cy + 250))
    image.save(path, "JPEG", quality=94)
    return path


def frame(video: Path, at: float, out: Path) -> np.ndarray:
    subprocess.run(["ffmpeg", "-y", "-loglevel", "error", "-ss", str(at), "-i", str(video),
                    "-frames:v", "1", str(out)], check=True)  # fmt: skip
    return np.asarray(Image.open(out).convert("RGB"), dtype=float)


async def make(tmp: Path, prompt: str, aspect: str = "9:16", seconds: float = 2.0) -> Path:
    provider = StillsProvider(tmp / "work", long_edge=640)
    request = ImageToVideoRequest(photo(tmp / "p.jpg"), prompt, "", seconds, aspect)
    job = await provider.submit(request)
    for _ in range(600):
        status = await provider.poll(job)
        if status.state != "running":
            break
        await asyncio.sleep(0.1)
    assert status.state == "succeeded", status.error
    out = tmp / f"{job.id}.mp4"
    await provider.fetch(job, status, out)
    return out


def test_choose_move_from_prompt() -> None:
    assert choose_move("Camera: slow dolly in") == "push_in"
    assert choose_move("Camera: gentle orbit, warm light") == "pan"
    assert choose_move("a reveal of the bottle") == "pull_out"
    assert choose_move("crane shot rising") == "tilt"
    assert choose_move("") == "push_in"


def test_output_sizes_are_even_and_in_ratio() -> None:
    assert output_size("9:16", 1920) == (1080, 1920)
    assert output_size("16:9", 1920) == (1920, 1080)
    assert output_size("1:1", 1920) == (1080, 1080)
    assert output_size("9:16", 640) == (360, 640)


def test_filter_never_contains_prompt_text() -> None:
    graph = build_filter(choose_move("'; drawtext=text=boom [x] orbit"), 360, 640, 60)
    assert "boom" not in graph and "drawtext" not in graph
    assert "zoompan" in graph and "d=60" in graph


@pytest.mark.parametrize("prompt", ["slow dolly in", "orbit", "pull out reveal", "crane tilt"])
async def test_each_move_renders_valid_clip_that_actually_moves(
    tmp_path: Path, prompt: str
) -> None:
    out = await make(tmp_path, prompt)
    info = await probe(out)
    assert info.video and (info.video.width, info.video.height) == (360, 640)
    assert info.video.codec == "h264" and info.video.pix_fmt == "yuv420p"
    assert abs(info.duration - 2.0) < 0.1 and abs(info.video.fps - 30) < 0.5
    first, last = frame(out, 0.0, tmp_path / "a.png"), frame(out, 1.9, tmp_path / "b.png")
    assert np.abs(first - last).mean() > 3.0  # the camera moved


async def test_first_frame_keeps_the_original_look(tmp_path: Path) -> None:
    """At the start the clip is the photo itself, cropped to shape: colours are unchanged."""
    out = await make(tmp_path, "dolly in")
    first = frame(out, 0.0, tmp_path / "a.png")
    h, w, _ = first.shape
    centre = first[h // 2 - 10 : h // 2 + 10, w // 2 - 10 : w // 2 + 10].mean(axis=(0, 1))
    assert abs(centre[0] - 200) < 12 and abs(centre[1] - 30) < 12 and abs(centre[2] - 30) < 12


async def test_square_and_wide(tmp_path: Path) -> None:
    for aspect, size in (("1:1", (360, 360)), ("16:9", (640, 360))):
        info = await probe(await make(tmp_path, "orbit", aspect, 1.0))
        assert info.video and (info.video.width, info.video.height) == size


async def test_bad_image_fails_cleanly_and_cancel_is_safe(tmp_path: Path) -> None:
    provider = StillsProvider(tmp_path / "work", long_edge=640)
    bad = tmp_path / "bad.jpg"
    bad.write_bytes(b"not an image")
    job = await provider.submit(ImageToVideoRequest(bad, "x", "", 2.0, "9:16"))
    for _ in range(100):
        status = await provider.poll(job)
        if status.state != "running":
            break
        await asyncio.sleep(0.1)
    assert status.state == "failed" and status.error and status.error.code == "render_failed"
    assert (await provider.poll(ProviderJob("nope", "stills"))).state == "failed"
    await provider.cancel(job)


def test_allowed_in_production_unlike_fake() -> None:
    settings = VideoGeneratorSettings(
        environment="production",
        video_provider_primary="stills",
        hmac_secret_laravel_to_ai="x",  # type: ignore[arg-type]
    )
    assert settings.video_provider_primary == "stills"
    with pytest.raises(ValueError, match="fake"):
        VideoGeneratorSettings(
            environment="production",
            video_provider_primary="fake",
            hmac_secret_laravel_to_ai="x",  # type: ignore[arg-type]
        )


def test_api_generation_with_stills_provider(tmp_path: Path) -> None:
    root = tmp_path / "media"
    photo(root / "in/a.jpg")
    photo(root / "in/b.jpg", (1200, 900))
    settings = VideoGeneratorSettings(
        environment="testing", video_provider_primary="stills", stills_long_edge=640
    )
    body = {
        "output_prefix": "gen/one",
        "shots": [
            {"shot_id": "s1", "image_key": "in/a.jpg", "prompt": "slow dolly in",
             "duration_seconds": 5.5, "request_seconds": 6.0},
            {"shot_id": "s2", "image_key": "in/b.jpg", "prompt": "gentle orbit",
             "duration_seconds": 5.5, "request_seconds": 6.0},
        ],
    }  # fmt: skip
    with TestClient(create_app(settings, storage=LocalStorage(root))) as client:
        job = client.post("/v1/generations", json=body)
        assert job.status_code == 202, job.text
        end = time.time() + 120
        data = {}
        while time.time() < end:
            data = client.get(f"/v1/generations/{job.json()['id']}").json()
            if data["status"] in ("succeeded", "failed"):
                break
            time.sleep(0.2)
        providers = client.get("/v1/providers").json()
    assert data["status"] == "succeeded", data.get("error")
    clips = data["result"]["clips"]
    assert [c["provider"] for c in clips] == ["stills", "stills"]
    assert all(c["provider_job_id"].startswith("stills-") for c in clips)
    assert data["result"]["total_cost_cents"] == 0
    for clip in clips:
        assert (root / clip["storage_key"]).is_file()
    assert any(p["name"] == "stills" for p in providers)
