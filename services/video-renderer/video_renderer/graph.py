"""Builds the FFmpeg command for a render. Pure functions: nothing here runs a process.

Everything that reaches FFmpeg comes from validated fields (numbers, enums, #rrggbb colours) or from
files we wrote ourselves; user text goes through a text file, never through the filter string.
"""

from __future__ import annotations

import textwrap
from dataclasses import dataclass
from pathlib import Path

from video_renderer.schemas import RenderRequest, Segment, TextOverlay

SIZES = {"9:16": (1080, 1920), "1:1": (1080, 1080), "16:9": (1920, 1080)}
PRESETS = {
    "draft": ("ultrafast", 30),
    "standard": ("veryfast", 20),
    "high": ("slow", 17),
}
# Platform safe zones, as a fraction of the height (about 150 px / 280 px on a 1920 px frame).
SAFE_TOP = 0.09
SAFE_BOTTOM = 0.15
MAX_LINES = 3


@dataclass(frozen=True)
class ResolvedInputs:
    """Local file paths for everything the request references."""

    segments: list[Path]
    logo: Path | None = None
    music: Path | None = None


@dataclass(frozen=True)
class FfmpegPlan:
    args: list[str]
    width: int
    height: int
    duration: float


def output_size(request: RenderRequest) -> tuple[int, int]:
    width, height = SIZES[request.aspect_ratio]
    if request.resolution == "preview":
        return width // 2, height // 2
    return width, height


def _num(value: float) -> str:
    return f"{value:.4f}".rstrip("0").rstrip(".") or "0"


def _escape_path(path: str) -> str:
    """Escape a path for use as a filter option value."""
    return path.replace("\\", "\\\\").replace(":", "\\:").replace("'", "\\'")


def wrap_text(text: str, font_px: int, video_width: int) -> str:
    # Average glyph width is about 0.6 of the font size; keep inside 84 % of the frame width.
    per_line = max(8, int(video_width * 0.84 / (font_px * 0.6)))
    lines = textwrap.wrap(text, width=per_line, break_long_words=True)[:MAX_LINES]
    return "\n".join(lines)


def segment_chain(segment: Segment, index: int, width: int, height: int, fps: int) -> str:
    """Filter chain that turns one input into a `width`x`height` stream of exact length."""
    seconds = segment.duration_seconds
    frames = max(1, round(seconds * fps))
    zoom = segment.zoom
    amount = zoom.amount if zoom.style != "none" else 0.0
    tail = f"setsar=1,format=yuv420p,fps={fps},settb=AVTB,setpts=PTS-STARTPTS"
    if segment.kind == "image":
        # A still: render a smooth zoom from a 2x upscaled copy so movement has no stair-stepping.
        if zoom.style == "out":
            z = f"1+{_num(amount)}*(1-on/{frames})"
        elif zoom.style == "in":
            z = f"1+{_num(amount)}*on/{frames}"
        else:
            z = "1"
        return (
            f"[{index}:v]scale={width * 2}:{height * 2}:force_original_aspect_ratio=increase,"
            f"crop={width * 2}:{height * 2},"
            f"zoompan=z='{z}':x='(iw-iw/zoom)*{_num(zoom.focus_x)}':"
            f"y='(ih-ih/zoom)*{_num(zoom.focus_y)}':d={frames}:s={width}x{height}:fps={fps},"
            f"{tail}[v{index}]"
        )
    chain = (
        f"[{index}:v]trim=duration={_num(seconds)},setpts=PTS-STARTPTS,fps={fps},"
        f"scale={width}:{height}:force_original_aspect_ratio=increase,crop={width}:{height}"
    )
    if amount > 0:
        progress = f"t/{_num(seconds)}" if zoom.style == "in" else f"(1-t/{_num(seconds)})"
        factor = f"(1+{_num(amount)}*{progress})"
        chain += (
            f",scale=w='{width}*{factor}':h='{height}*{factor}':eval=frame,"
            f"crop={width}:{height}:x='(in_w-{width})*{_num(zoom.focus_x)}':"
            f"y='(in_h-{height})*{_num(zoom.focus_y)}'"
        )
    return f"{chain},{tail}[v{index}]"


def chain_segments(request: RenderRequest) -> tuple[list[str], str]:
    """Join the segments with crossfades. Returns (filters, final video label)."""
    n = len(request.segments)
    if n == 1:
        return [], "v0"
    fade = request.transition.duration_seconds
    filters: list[str] = []
    running = request.segments[0].duration_seconds
    label = "v0"
    for i in range(1, n):
        offset = running - fade
        out = f"x{i}"
        filters.append(
            f"[{label}][v{i}]xfade=transition={request.transition.type}:"
            f"duration={_num(fade)}:offset={_num(offset)}[{out}]"
        )
        running += request.segments[i].duration_seconds - fade
        label = out
    return filters, label


def overlay_filter(
    overlay: TextOverlay, text_file: Path, font_path: str, width: int, height: int
) -> str:
    scale = width / 1080
    size = max(14, round(overlay.font_size * scale))
    pad = round(size * 0.3)
    if overlay.position == "top":
        y = f"h*{SAFE_TOP}"
    elif overlay.position == "center":
        y = "(h-text_h)/2"
    else:
        y = f"h-h*{SAFE_BOTTOM}-text_h"
    start, end = _num(overlay.start_seconds), _num(overlay.end_seconds)
    fade = min(0.4, (overlay.end_seconds - overlay.start_seconds) / 3)
    alpha = (
        f"if(lt(t,{start}+{_num(fade)}),(t-{start})/{_num(fade)},"
        f"if(gt(t,{end}-{_num(fade)}),({end}-t)/{_num(fade)},1))"
    )
    color = "0x" + overlay.color[1:]
    parts = [
        f"fontfile='{_escape_path(font_path)}'",
        f"textfile='{_escape_path(str(text_file))}'",
        "expansion=none",
        f"fontsize={size}",
        f"fontcolor={color}",
        "x=(w-text_w)/2",
        f"y={y}",
        "line_spacing=" + str(round(size * 0.2)),
        f"alpha='{alpha}'",
        f"enable='between(t,{start},{end})'",
    ]
    if overlay.background:
        parts += ["box=1", "boxcolor=black@0.45", f"boxborderw={pad}"]
    return "drawtext=" + ":".join(parts)


def logo_filters(
    request: RenderRequest, input_index: int, width: int, height: int
) -> tuple[str, str]:
    """Returns (logo preparation filter, overlay filter body)."""
    logo = request.logo
    if logo is None:
        raise ValueError("no logo")
    target = max(16, round(width * logo.width_percent / 100))
    margin_x = round(width * logo.margin_percent / 100)
    margin_y = round(height * logo.margin_percent / 100)
    if logo.corner.startswith("top"):
        margin_y = max(margin_y, round(height * SAFE_TOP))  # stay clear of the platform's top UI
    elif logo.corner.startswith("bottom"):
        margin_y = max(margin_y, round(height * SAFE_BOTTOM))
    x = str(margin_x) if logo.corner.endswith("left") else f"main_w-overlay_w-{margin_x}"
    y = str(margin_y) if logo.corner.startswith("top") else f"main_h-overlay_h-{margin_y}"
    prep = (
        f"[{input_index}:v]format=rgba,scale={target}:-2,"
        f"colorchannelmixer=aa={_num(logo.opacity)}[logo]"
    )
    enable = ""
    if logo.start_seconds is not None or logo.end_seconds is not None:
        start = _num(logo.start_seconds or 0)
        end = _num(logo.end_seconds or request.duration_seconds)
        enable = f":enable='between(t,{start},{end})'"
    return prep, f"overlay=x={x}:y={y}{enable}"


def audio_chain(request: RenderRequest, input_index: int | None) -> str:
    total = _num(request.duration_seconds)
    if request.music is None or input_index is None:
        return f"anullsrc=r=48000:cl=stereo:d={total}[aout]"
    music = request.music
    parts = [
        f"[{input_index}:a]aformat=sample_rates=48000:channel_layouts=stereo",
        f"atrim=0:{total}",
        "asetpts=PTS-STARTPTS",
    ]
    if music.normalize:
        parts.append("loudnorm=I=-16:TP=-1.5:LRA=11")
    parts.append(f"volume={_num(music.volume)}")
    if music.fade_in_seconds > 0:
        parts.append(f"afade=t=in:st=0:d={_num(music.fade_in_seconds)}")
    if music.fade_out_seconds > 0:
        start = max(0.0, request.duration_seconds - music.fade_out_seconds)
        parts.append(f"afade=t=out:st={_num(start)}:d={_num(music.fade_out_seconds)}")
    parts.append("aresample=48000")
    return ",".join(parts) + "[aout]"


def build_plan(
    request: RenderRequest,
    inputs: ResolvedInputs,
    output: Path,
    workdir: Path,
    font_path: str,
    *,
    threads: int = 0,
) -> FfmpegPlan:
    width, height = output_size(request)
    fps = request.fps
    args: list[str] = []

    count = 0

    def add_input(path: Path, *pre: str) -> int:
        nonlocal count
        # Only local files may be opened: no network or playlist indirection.
        args.extend([*pre, "-protocol_whitelist", "file", "-i", str(path)])
        count += 1
        return count - 1

    for path in inputs.segments:
        add_input(path)
    logo_index = add_input(inputs.logo) if inputs.logo is not None else None
    music_index = None
    if inputs.music is not None:
        looping = ["-stream_loop", "-1"] if request.music and request.music.loop else []
        music_index = add_input(inputs.music, *looping)

    filters = [segment_chain(s, i, width, height, fps) for i, s in enumerate(request.segments)]
    joins, label = chain_segments(request)
    filters += joins

    final = label
    if request.grade is not None:
        grade = (
            f"eq=contrast={_num(request.grade.contrast)}"
            f":saturation={_num(request.grade.saturation)}"
        )
        if request.grade.vignette:
            grade += ",vignette=PI/6"
        filters.append(f"[{final}]{grade}[graded]")
        final = "graded"
    if logo_index is not None:
        prep, overlay = logo_filters(request, logo_index, width, height)
        filters += [prep, f"[{final}][logo]{overlay}[logoed]"]
        final = "logoed"
    for i, overlay_spec in enumerate(request.overlays):
        text_file = workdir / f"overlay{i}.txt"
        text_file.write_text(
            wrap_text(overlay_spec.text, overlay_spec.font_size, 1080), encoding="utf-8"
        )
        out = f"t{i}"
        filters.append(
            f"[{final}]{overlay_filter(overlay_spec, text_file, font_path, width, height)}[{out}]"
        )
        final = out
    filters.append(
        f"[{final}]trim=duration={_num(request.duration_seconds)},setpts=PTS-STARTPTS[vout]"
    )
    filters.append(audio_chain(request, music_index))

    preset, crf = PRESETS[request.quality]
    args += [
        "-filter_complex", ";".join(filters),
        "-map", "[vout]", "-map", "[aout]",
        "-c:v", "libx264", "-preset", preset, "-crf", str(crf),
        "-profile:v", "high", "-pix_fmt", "yuv420p", "-r", str(fps),
        "-c:a", "aac", "-b:a", "192k", "-ar", "48000", "-ac", "2",
        "-movflags", "+faststart", "-t", _num(request.duration_seconds),
    ]  # fmt: skip
    if threads:
        args += ["-threads", str(threads)]
    args.append(str(output))
    return FfmpegPlan(args=args, width=width, height=height, duration=request.duration_seconds)
