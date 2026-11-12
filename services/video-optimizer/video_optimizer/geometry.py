"""Pure helpers that decide output sizes and FFmpeg filters."""

from __future__ import annotations

from dataclasses import dataclass

RATIOS: dict[str, float] = {"9:16": 9 / 16, "1:1": 1.0, "4:5": 4 / 5, "16:9": 16 / 9}


def even(value: float) -> int:
    return max(2, int(value) // 2 * 2)


@dataclass(frozen=True)
class Fit:
    width: int
    height: int
    filter: str


def plan_fit(src_w: int, src_h: int, aspect: str, mode: str, focus_x: float) -> Fit:
    """Output size and a video filter chain ending in `setsar=1`.

    `crop` never upscales. `pad_blur` keeps the whole picture on a blurred copy of itself.
    """
    target = RATIOS[aspect]
    source = src_w / src_h
    if mode == "crop":
        if abs(target - source) < 0.005:
            return Fit(even(src_w), even(src_h), f"scale={even(src_w)}:{even(src_h)},setsar=1")
        if target < source:  # narrower: cut the sides
            height = even(src_h)
            width = even(height * target)
            x = f"(iw-{width})*{focus_x:.4f}"
            return Fit(width, height, f"crop={width}:{height}:{x}:0,setsar=1")
        width = even(src_w)  # wider: cut top and bottom
        height = even(width / target)
        return Fit(width, height, f"crop={width}:{height}:0:(ih-{height})/2,setsar=1")
    # pad_blur: build a canvas of the target shape and place the whole picture on it.
    if target < source:
        width = even(src_w)
        height = even(width / target)
    else:
        height = even(src_h)
        width = even(height * target)
    chain = (
        f"split[bg][fg];"
        f"[bg]scale={width}:{height}:force_original_aspect_ratio=increase,"
        f"crop={width}:{height},boxblur=24:4[b];"
        f"[fg]scale={width}:{height}:force_original_aspect_ratio=decrease[f];"
        f"[b][f]overlay=(W-w)/2:(H-h)/2,setsar=1"
    )
    return Fit(width, height, chain)


def bitrate_for(max_bytes: int, seconds: float, audio_bps: int, safety: float = 0.92) -> int:
    """Video bit rate (bits per second) that should keep a file under `max_bytes`."""
    total = max_bytes * 8 * safety / max(seconds, 0.1)
    return max(150_000, int(total - audio_bps))
