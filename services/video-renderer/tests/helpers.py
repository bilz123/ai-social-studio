from __future__ import annotations

import re
import subprocess
from pathlib import Path

from PIL import Image


def ffmpeg(*args: str) -> subprocess.CompletedProcess[str]:
    return subprocess.run(
        ["ffmpeg", "-y", "-hide_banner", "-loglevel", "info", *args],
        check=True, capture_output=True, text=True,
    )  # fmt: skip


def make_clip(path: Path, seconds: float, source: str = "testsrc2", size: str = "360x640") -> Path:
    path.parent.mkdir(parents=True, exist_ok=True)
    ffmpeg("-f", "lavfi", "-i", f"{source}=size={size}:rate=30:duration={seconds}",
           "-pix_fmt", "yuv420p", str(path))  # fmt: skip
    return path


def make_image(path: Path, size: tuple[int, int] = (800, 1400)) -> Path:
    path.parent.mkdir(parents=True, exist_ok=True)
    image = Image.new("RGB", size, (30, 60, 120))
    for i in range(0, size[0], 80):  # a grid so zooming visibly changes the picture
        for j in range(0, size[1], 80):
            if (i // 80 + j // 80) % 2 == 0:
                image.paste((230, 200, 60), (i, j, i + 80, j + 80))
    image.save(path, "JPEG", quality=92)
    return path


def make_logo(path: Path, colour: tuple[int, int, int] = (255, 0, 0)) -> Path:
    path.parent.mkdir(parents=True, exist_ok=True)
    Image.new("RGBA", (200, 100), (*colour, 255)).save(path, "PNG")
    return path


def make_music(path: Path, seconds: float = 3.0) -> Path:
    path.parent.mkdir(parents=True, exist_ok=True)
    ffmpeg("-f", "lavfi", "-i", f"sine=frequency=440:duration={seconds}", str(path))
    return path


def frame(video: Path, at: float, out: Path) -> Image.Image:
    ffmpeg("-ss", str(at), "-i", str(video), "-frames:v", "1", str(out))
    return Image.open(out).convert("RGB")


def mean_volume_db(video: Path) -> float:
    result = ffmpeg("-i", str(video), "-af", "volumedetect", "-vn", "-f", "null", "-")
    match = re.search(r"mean_volume: (-?[\d.]+|-inf) dB", result.stderr)
    assert match, result.stderr[-500:]
    return float("-inf") if match.group(1) == "-inf" else float(match.group(1))


def region_difference(a: Image.Image, b: Image.Image, box: tuple[int, int, int, int]) -> float:
    import numpy as np

    first = np.asarray(a.crop(box), dtype=float)
    second = np.asarray(b.crop(box), dtype=float)
    return float(np.abs(first - second).mean())
