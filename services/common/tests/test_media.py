from __future__ import annotations

import shutil
import subprocess
from pathlib import Path

import pytest

from studio_common.errors import ServiceError
from studio_common.media import probe, run_ffmpeg

pytestmark = pytest.mark.skipif(shutil.which("ffmpeg") is None, reason="ffmpeg not installed")


def make_clip(path: Path, seconds: int = 1, audio: bool = True) -> None:
    args = ["ffmpeg", "-y", "-loglevel", "error", "-f", "lavfi", "-i",
            f"testsrc=size=320x240:rate=25:duration={seconds}"]  # fmt: skip
    if audio:
        args += ["-f", "lavfi", "-i", f"sine=frequency=440:duration={seconds}"]
    args += ["-pix_fmt", "yuv420p", str(path)]
    subprocess.run(args, check=True)


async def test_probe_describes_video_and_audio(tmp_path: Path) -> None:
    clip = tmp_path / "a.mp4"
    make_clip(clip)
    info = await probe(clip)
    assert info.video is not None and (info.video.width, info.video.height) == (320, 240)
    assert round(info.video.fps) == 25
    assert info.audio is not None and info.audio.codec == "aac"
    assert 0.9 < info.duration < 1.2


async def test_probe_rejects_non_media(tmp_path: Path) -> None:
    junk = tmp_path / "junk.mp4"
    junk.write_bytes(b"this is not a video")
    with pytest.raises(ServiceError) as caught:
        await probe(junk)
    assert caught.value.code == "invalid_media"
    assert caught.value.status_code == 422


async def test_ffmpeg_errors_hide_stderr(tmp_path: Path) -> None:
    with pytest.raises(ServiceError) as caught:
        await run_ffmpeg(["-i", str(tmp_path / "missing.mp4"), str(tmp_path / "o.mp4")])
    assert caught.value.code == "postprocess_failed"
    assert str(tmp_path) not in caught.value.detail
