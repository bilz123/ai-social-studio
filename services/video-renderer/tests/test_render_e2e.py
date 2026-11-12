"""Real FFmpeg renders on small synthetic media, checked with ffprobe and by looking at pixels."""

from __future__ import annotations

import time
from pathlib import Path
from typing import Any

import numpy as np
import pytest
from fastapi.testclient import TestClient
from studio_common.media import probe
from studio_common.storage import LocalStorage

from tests.helpers import (
    frame,
    make_clip,
    make_image,
    make_logo,
    make_music,
    mean_volume_db,
    region_difference,
)
from video_renderer.main import create_app
from video_renderer.settings import VideoRendererSettings

FONT_OK = Path("/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf").is_file()
pytestmark = pytest.mark.skipif(not FONT_OK, reason="DejaVu font not installed")


@pytest.fixture
def media(tmp_path: Path) -> Path:
    root = tmp_path / "media"
    make_clip(root / "in/a.mp4", 4.0)
    make_clip(root / "in/b.mp4", 4.0, source="smptebars")
    make_image(root / "in/still.jpg")
    make_logo(root / "in/logo.png")
    make_music(root / "in/music.wav", 3.0)
    return root


def run(media: Path, payload: dict[str, Any]) -> dict[str, Any]:
    app = create_app(VideoRendererSettings(environment="testing"), storage=LocalStorage(media))
    with TestClient(app) as client:
        response = client.post("/v1/renders", json=payload)
        assert response.status_code == 202, response.text
        job_id = response.json()["id"]
        end = time.time() + 120
        while time.time() < end:
            data: dict[str, Any] = client.get(f"/v1/renders/{job_id}").json()
            if data["status"] in ("succeeded", "failed", "canceled"):
                return data
            time.sleep(0.1)
    raise AssertionError("render did not finish")


def payload(**over: Any) -> dict[str, Any]:
    base: dict[str, Any] = {
        "output_key": "out/final.mp4",
        "resolution": "preview",
        "duration_seconds": 7.0,
        "segments": [
            {"kind": "clip", "key": "in/a.mp4", "duration_seconds": 4.0},
            {"kind": "clip", "key": "in/b.mp4", "duration_seconds": 4.0},
        ],
        "transition": {"type": "fade", "duration_seconds": 1.0},
        "quality": "draft",
    }
    return {**base, **over}


async def test_two_clips_with_crossfade_make_a_valid_master(media: Path) -> None:
    done = run(
        media, payload(quality="standard")
    )  # `draft` uses ultrafast, which lowers the profile
    assert done["status"] == "succeeded", done
    result = done["result"]
    assert (result["width"], result["height"], result["fps"]) == (540, 960, 30.0)
    assert result["has_audio"] is True
    info = await probe(media / "out/final.mp4")
    assert info.video is not None and info.video.codec == "h264"
    assert info.video.pix_fmt == "yuv420p" and info.video.profile == "High"
    assert info.audio is not None and (info.audio.codec, info.audio.sample_rate) == ("aac", 48000)
    assert info.audio.channels == 2
    assert abs(info.duration - 7.0) < 0.15
    # +faststart: the index (moov) must come before the media data.
    head = (media / "out/final.mp4").read_bytes()[:4096]
    assert head.find(b"moov") != -1 and head.find(b"moov") < (head.find(b"mdat") % 10**9 or 10**9)


def test_silent_when_there_is_no_music_and_audible_with_music(media: Path) -> None:
    silent = run(media, payload(output_key="out/silent.mp4"))
    assert silent["status"] == "succeeded", silent
    assert mean_volume_db(media / "out/silent.mp4") < -60
    music = {"key": "in/music.wav", "volume": 0.8, "normalize": True, "loop": True}
    loud = run(media, payload(output_key="out/music.mp4", music=music))
    assert loud["status"] == "succeeded", loud
    assert mean_volume_db(media / "out/music.mp4") > -40
    assert loud["result"]["duration_seconds"] == pytest.approx(7.0, abs=0.15)  # looped to length


def test_text_overlay_appears_only_while_it_is_enabled(media: Path, tmp_path: Path) -> None:
    overlays = [
        {
            "text": "Free shipping today",
            "start_seconds": 2,
            "end_seconds": 5,
            "position": "bottom",
            "font_size": 90,
        }
    ]
    plain = run(media, payload(output_key="out/plain.mp4"))
    texted = run(media, payload(output_key="out/text.mp4", overlays=overlays))
    assert plain["status"] == texted["status"] == "succeeded", texted
    bottom = (20, 700, 520, 830)  # where the bottom caption sits on a 540x960 frame
    during_plain = frame(media / "out/plain.mp4", 3.5, tmp_path / "p.png")
    during_text = frame(media / "out/text.mp4", 3.5, tmp_path / "t.png")
    before_plain = frame(media / "out/plain.mp4", 0.5, tmp_path / "p0.png")
    before_text = frame(media / "out/text.mp4", 0.5, tmp_path / "t0.png")
    assert region_difference(during_plain, during_text, bottom) > 8
    assert region_difference(before_plain, before_text, bottom) < 1


def test_logo_is_placed_in_the_chosen_corner_and_respects_the_safe_zone(
    media: Path, tmp_path: Path
) -> None:
    logo = {"key": "in/logo.png", "corner": "top_right", "width_percent": 20, "opacity": 1.0}
    done = run(media, payload(output_key="out/logo.mp4", logo=logo, grade=None))
    assert done["status"] == "succeeded", done
    image = frame(media / "out/logo.mp4", 1.0, tmp_path / "l.png")
    width, height = image.size
    margin_x, margin_y = round(width * 0.05), round(height * 0.09)  # top safe zone wins
    logo_w = round(width * 0.20)
    plain = run(media, payload(output_key="out/nologo.mp4", grade=None))
    assert plain["status"] == "succeeded", plain
    reference = frame(media / "out/nologo.mp4", 1.0, tmp_path / "n.png")
    pixels, base = np.asarray(image), np.asarray(reference)
    column = width - margin_x - logo_w // 2
    red, green, blue = (int(v) for v in pixels[margin_y + 6, column])
    assert red > 200 and green < 60 and blue < 60  # the red logo
    # Compression is lossy, so "untouched" means "almost identical" rather than bit-exact.
    strip = np.abs(pixels[: margin_y - 2, :].astype(int) - base[: margin_y - 2, :].astype(int))
    assert strip.mean() < 2  # the platform's top UI strip is untouched
    left = (slice(margin_y + 6, margin_y + 40), slice(0, width // 2))
    assert np.abs(pixels[left].astype(int) - base[left].astype(int)).mean() < 2


def test_still_image_gets_a_cinematic_zoom(media: Path, tmp_path: Path) -> None:
    zoomed = {
        "kind": "image",
        "key": "in/still.jpg",
        "duration_seconds": 3.0,
        "zoom": {"style": "in", "amount": 0.3},
    }
    flat = {**zoomed, "zoom": {"style": "none"}}
    a = run(
        media, payload(output_key="out/z.mp4", duration_seconds=3.0, segments=[zoomed], grade=None)
    )
    b = run(
        media, payload(output_key="out/f.mp4", duration_seconds=3.0, segments=[flat], grade=None)
    )
    assert a["status"] == b["status"] == "succeeded", (a, b)
    box = (0, 0, 540, 960)
    start_z, end_z = (
        frame(media / "out/z.mp4", 0.1, tmp_path / "z0.png"),
        frame(media / "out/z.mp4", 2.8, tmp_path / "z1.png"),
    )
    start_f, end_f = (
        frame(media / "out/f.mp4", 0.1, tmp_path / "f0.png"),
        frame(media / "out/f.mp4", 2.8, tmp_path / "f1.png"),
    )
    assert region_difference(start_z, end_z, box) > 15  # the picture moves closer
    assert region_difference(start_f, end_f, box) < 3  # a still stays still
    # Both begin on nearly the same framing: far closer than the zoomed end is to its start.
    assert region_difference(start_z, start_f, box) < region_difference(start_z, end_z, box) / 2


def test_clip_zoom_changes_the_framing_over_time(media: Path, tmp_path: Path) -> None:
    seg = {
        "kind": "clip",
        "key": "in/b.mp4",
        "duration_seconds": 3.0,
        "zoom": {"style": "in", "amount": 0.3},
    }
    flat = {**seg, "zoom": {"style": "none"}}
    a = run(
        media, payload(output_key="out/cz.mp4", duration_seconds=3.0, segments=[seg], grade=None)
    )
    b = run(
        media, payload(output_key="out/cf.mp4", duration_seconds=3.0, segments=[flat], grade=None)
    )
    assert a["status"] == b["status"] == "succeeded"
    late_z = frame(media / "out/cz.mp4", 2.8, tmp_path / "a.png")
    late_f = frame(media / "out/cf.mp4", 2.8, tmp_path / "b.png")
    assert region_difference(late_z, late_f, (0, 0, 540, 960)) > 6


def test_aspect_ratios(media: Path) -> None:
    still = {"kind": "image", "key": "in/still.jpg", "duration_seconds": 3.0}
    for ratio, size in (("1:1", (540, 540)), ("16:9", (960, 540))):
        done = run(
            media,
            payload(
                output_key=f"out/{ratio[0]}.mp4",
                aspect_ratio=ratio,
                duration_seconds=3.0,
                segments=[still],
            ),
        )
        assert done["status"] == "succeeded", done
        assert (done["result"]["width"], done["result"]["height"]) == size


@pytest.mark.parametrize(
    ("setup", "code"),
    [
        ("not_a_video_clip", "invalid_media"),
        ("hls_playlist", "invalid_media"),
        ("clip_too_short", "clip_too_short"),
        ("image_as_clip", "unsupported_input"),
        ("text_as_logo", "invalid_media"),
        ("short_music_no_loop", "music_too_short"),
    ],
)
def test_bad_inputs_are_refused_before_ffmpeg_runs(media: Path, setup: str, code: str) -> None:
    body = payload(
        duration_seconds=3.0,
        segments=[{"kind": "clip", "key": "in/a.mp4", "duration_seconds": 3.0}],
    )
    if setup == "not_a_video_clip":
        (media / "in/bad.mp4").write_bytes(b"nothing to see")
        body["segments"][0]["key"] = "in/bad.mp4"
    elif setup == "hls_playlist":
        (media / "in/evil.mp4").write_text("#EXTM3U\n#EXTINF:3,\nhttp://169.254.169.254/x.ts\n")
        body["segments"][0]["key"] = "in/evil.mp4"
    elif setup == "clip_too_short":
        body["segments"][0]["duration_seconds"] = 3.0
        make_clip(media / "in/short.mp4", 1.0)
        body["segments"][0]["key"] = "in/short.mp4"
    elif setup == "image_as_clip":
        body["segments"][0]["key"] = "in/still.jpg"
    elif setup == "text_as_logo":
        (media / "in/logo.txt").write_text("hello")
        body["logo"] = {"key": "in/logo.txt"}
    elif setup == "short_music_no_loop":
        body["music"] = {"key": "in/music.wav", "loop": False}
        body["duration_seconds"] = 3.0
        make_music(media / "in/music.wav", 1.0)
    done = run(media, body)
    assert done["status"] == "failed", done
    assert done["error"]["code"] == code


def test_missing_inputs_are_404_and_nothing_is_rendered(media: Path) -> None:
    app = create_app(VideoRendererSettings(environment="testing"), storage=LocalStorage(media))
    with TestClient(app) as client:
        response = client.post(
            "/v1/renders",
            json=payload(
                segments=[{"kind": "clip", "key": "in/missing.mp4", "duration_seconds": 8.0}],
                duration_seconds=8.0,
            ),
        )
    assert response.status_code == 404 and response.json()["code"] == "media_not_found"
    assert not (media / "out").exists()


def test_job_reports_progress_and_supports_idempotency_and_cancel(media: Path) -> None:
    app = create_app(VideoRendererSettings(environment="testing"), storage=LocalStorage(media))
    with TestClient(app) as client:
        body = payload(idempotency_key="k1", quality="draft")
        first = client.post("/v1/renders", json=body)
        second = client.post("/v1/renders", json=body)
        assert (first.status_code, second.status_code) == (202, 200)
        assert first.json()["id"] == second.json()["id"]
        job_id = first.json()["id"]
        end = time.time() + 60
        seen: set[str | None] = set()
        while time.time() < end:
            data = client.get(f"/v1/renders/{job_id}").json()
            seen.add(data["stage"])
            if data["status"] == "succeeded":
                break
            time.sleep(0.02)
        assert data["progress"] == 1.0
        assert client.get("/v1/renders/unknown").status_code == 404
        # Cancelling a finished job is harmless.
        assert client.post(f"/v1/renders/{job_id}/cancel").json()["status"] == "succeeded"


def test_health_reports_ffmpeg_and_font(media: Path) -> None:
    app = create_app(VideoRendererSettings(environment="testing"), storage=LocalStorage(media))
    with TestClient(app) as client:
        ready = client.get("/readyz")
    assert ready.status_code == 200
    assert set(ready.json()["checks"]) == {"storage", "job_store", "ffmpeg", "font"}
