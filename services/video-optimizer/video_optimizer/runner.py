"""Optimises a finished video: exports, size control, thumbnails and final validation."""

from __future__ import annotations

import logging
import tempfile
from pathlib import Path
from typing import Any

from PIL import Image, ImageFilter, ImageStat
from studio_common.errors import ServiceError
from studio_common.jobs import JobContext
from studio_common.media import MediaInfo, probe, run_ffmpeg, run_ffmpeg_with_progress
from studio_common.storage import Storage

from video_optimizer.geometry import RATIOS, bitrate_for, even, plan_fit
from video_optimizer.schemas import (
    OptimizeRequest,
    OptimizeResult,
    OutputResult,
    OutputSpec,
    ThumbnailOptions,
    ThumbnailResult,
    ValidationReport,
)
from video_optimizer.settings import VideoOptimizerSettings
from video_optimizer.validation import facts_from, validate_facts

logger = logging.getLogger(__name__)

SOURCE_FORMATS = ("mov,mp4", "matroska,webm")
AUDIO_BPS = 128_000
MAX_ATTEMPTS = 3


def check_source(info: MediaInfo, settings: VideoOptimizerSettings) -> None:
    if not info.format_name.startswith(SOURCE_FORMATS) or info.video is None:
        raise ServiceError(422, "unsupported_input", "The source is not an MP4, MOV or WebM video.")
    if info.video.width * info.video.height > settings.max_input_pixels:
        raise ServiceError(422, "input_too_large", "The source has too many pixels.")
    if info.duration > settings.max_input_seconds:
        raise ServiceError(422, "input_too_long", "The source is too long.")
    if info.duration < 0.5:
        raise ServiceError(422, "input_too_short", "The source is too short.")


def _encode_args(
    source: Path,
    target: Path,
    spec: OutputSpec,
    filter_chain: str,
    *,
    has_audio: bool,
    video_bps: int | None,
    settings: VideoOptimizerSettings,
) -> list[str]:
    args = ["-i", str(source)]
    if not has_audio:
        args += ["-f", "lavfi", "-i", "anullsrc=r=48000:cl=stereo"]
    graph = f"[0:v:0]{filter_chain}[v]" if ";" in filter_chain else None
    if graph:
        args += ["-filter_complex", graph, "-map", "[v]"]
    else:
        args += ["-vf", filter_chain, "-map", "0:v:0"]
    args += ["-map", "0:a:0" if has_audio else "1:a:0"]
    args += ["-c:v", "libx264", "-preset", spec.preset, "-profile:v", "high", "-pix_fmt", "yuv420p"]
    if video_bps is None:
        args += ["-crf", str(spec.crf)]
    else:
        args += ["-b:v", str(video_bps), "-maxrate", str(int(video_bps * 1.4)),
                 "-bufsize", str(video_bps * 2)]  # fmt: skip
    args += ["-g", "60", "-r", "30", "-threads", str(settings.ffmpeg_threads)]
    args += ["-c:a", "aac", "-b:a", str(AUDIO_BPS), "-ar", "48000", "-ac", "2"]
    if not has_audio:
        args += ["-shortest"]
    args += ["-movflags", "+faststart", "-map_metadata", "-1", str(target)]
    return args


async def _export(
    source: Path,
    info: MediaInfo,
    spec: OutputSpec,
    workdir: Path,
    ctx: JobContext,
    settings: VideoOptimizerSettings,
    share: tuple[float, float],
) -> tuple[Path, MediaInfo, int, int, int]:
    assert info.video is not None  # noqa: S101
    fit = plan_fit(info.video.width, info.video.height, spec.aspect_ratio, spec.mode, spec.focus_x)
    limit_mb = min(spec.max_size_mb or settings.max_output_mb, settings.max_output_mb)
    max_bytes = int(limit_mb * 1024 * 1024)
    bps: int | None = None
    target = workdir / f"{spec.name}.mp4"
    last = 0
    for attempt in range(1, MAX_ATTEMPTS + 1):
        low, high = share

        async def progress(
            fraction: float, a: int = attempt, low: float = low, high: float = high
        ) -> None:
            span = (high - low) / MAX_ATTEMPTS
            await ctx.update(f"encoding_{spec.name}", low + span * (a - 1 + fraction))

        await run_ffmpeg_with_progress(
            _encode_args(source, target, spec, fit.filter, has_audio=info.audio is not None,
                         video_bps=bps, settings=settings),
            total_seconds=info.duration,
            on_progress=progress,
            timeout=settings.encode_timeout_seconds,
        )  # fmt: skip
        out = await probe(target)
        last = out.size_bytes
        if out.size_bytes <= max_bytes:
            return target, out, fit.width, fit.height, attempt
        # Too big: switch to (or lower) a bit-rate target and try again.
        base = bps if bps is not None else int((out.bit_rate or 4_000_000) * 0.9)
        bps = min(int(base * 0.85), bitrate_for(max_bytes, out.duration, AUDIO_BPS))
        logger.info("size_retry", extra={"output": spec.name, "bytes": last, "next_bps": bps})
    raise ServiceError(
        422,
        "size_target_unreachable",
        f"'{spec.name}' is still {last / 1048576:.1f} MB; "
        f"the {limit_mb:g} MB target cannot be met.",
    )


def _sharpness(path: Path) -> float:
    with Image.open(path) as image:
        gray = image.convert("L").resize((270, 480) if image.height >= image.width else (480, 270))
        return float(ImageStat.Stat(gray.filter(ImageFilter.FIND_EDGES)).var[0])


async def _thumbnails(
    source: Path,
    info: MediaInfo,
    options: ThumbnailOptions,
    workdir: Path,
    ctx: JobContext,
) -> list[tuple[str, Path, float, int, int]]:
    assert info.video is not None  # noqa: S101
    duration = info.duration
    if options.at_seconds is not None:
        moment = min(options.at_seconds, max(duration - 0.1, 0.0))
    else:
        await ctx.update("choosing_thumbnail", 0.85)
        start, end = min(0.5, duration * 0.1), duration - min(0.5, duration * 0.1)
        step = (end - start) / max(options.candidates - 1, 1)
        best, moment = -1.0, start
        for index in range(options.candidates):
            at = start + step * index
            probe_path = workdir / f"cand{index}.jpg"
            await run_ffmpeg(["-ss", f"{at:.3f}", "-i", str(source), "-frames:v", "1",
                              "-vf", "scale=320:-2", str(probe_path)], timeout=60)  # fmt: skip
            score = _sharpness(probe_path)
            if score > best:
                best, moment = score, at
    results: list[tuple[str, Path, float, int, int]] = []
    for item in options.items:
        target_ratio = RATIOS[item.aspect_ratio]
        width = even(item.width or (1080 if target_ratio <= 1 else 1280))
        height = even(width / target_ratio)
        path = workdir / f"thumb_{item.name}.jpg"
        vf = (f"scale={width}:{height}:force_original_aspect_ratio=increase,"
              f"crop={width}:{height},setsar=1")  # fmt: skip
        await run_ffmpeg(["-ss", f"{moment:.3f}", "-i", str(source), "-frames:v", "1", "-vf", vf,
                          "-q:v", "3", str(path)], timeout=60)  # fmt: skip
        results.append((item.name, path, moment, width, height))
    return results


async def optimize(
    request: OptimizeRequest,
    ctx: JobContext,
    storage: Storage,
    settings: VideoOptimizerSettings,
) -> dict[str, Any]:
    if len(request.outputs) > settings.max_outputs:
        raise ServiceError(422, "too_many_outputs", f"At most {settings.max_outputs} outputs.")
    await ctx.update("checking_input", 0.02)
    async with storage.local_copy(request.video_key) as source:
        info = await probe(source)
        check_source(info, settings)
        source_facts = facts_from(info, source)
        outputs: list[OutputResult] = []
        thumbs: list[ThumbnailResult] = []
        with tempfile.TemporaryDirectory(prefix="optimize-") as directory:
            workdir = Path(directory)
            count = len(request.outputs)
            for index, spec in enumerate(request.outputs):
                share = (0.05 + 0.8 * index / count, 0.05 + 0.8 * (index + 1) / count)
                path, out, width, height, attempts = await _export(
                    source, info, spec, workdir, ctx, settings, share
                )
                report: ValidationReport | None = None
                if spec.profile is not None:
                    await ctx.update(f"validating_{spec.name}", share[1])
                    report = validate_facts(
                        facts_from(out, path), spec.profile,
                        max_mb=int(spec.max_size_mb or settings.max_output_mb),
                    )  # fmt: skip
                    if not report.valid:
                        failed = [
                            c.name for c in report.checks if not c.passed and c.severity == "error"
                        ]
                        logger.error(
                            "output_invalid", extra={"output": spec.name, "failed": failed}
                        )
                        raise ServiceError(
                            422, "output_invalid",
                            f"'{spec.name}' failed validation: {', '.join(failed)}.",
                        )  # fmt: skip
                key = f"{request.output_prefix}/{spec.name}.mp4"
                await storage.store_file(path, key)
                outputs.append(
                    OutputResult(
                        name=spec.name, storage_key=key, aspect_ratio=spec.aspect_ratio,
                        width=width, height=height, size_bytes=out.size_bytes,
                        duration_seconds=round(out.duration, 3), bit_rate=out.bit_rate,
                        original_size_bytes=info.size_bytes, attempts=attempts, validation=report,
                    )
                )  # fmt: skip
            if request.thumbnails is not None and request.thumbnails.items:
                for name, path, moment, width, height in await _thumbnails(
                    source, info, request.thumbnails, workdir, ctx
                ):
                    key = f"{request.output_prefix}/{name}.jpg"
                    size = await storage.store_file(path, key)
                    thumbs.append(
                        ThumbnailResult(name=name, storage_key=key, width=width, height=height,
                                        size_bytes=size, at_seconds=round(moment, 3))
                    )  # fmt: skip
    logger.info("optimize_finished", extra={"outputs": len(outputs), "thumbnails": len(thumbs)})
    return OptimizeResult(source=source_facts, outputs=outputs, thumbnails=thumbs).model_dump()
