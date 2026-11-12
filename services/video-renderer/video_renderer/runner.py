"""Resolves inputs, checks them, runs FFmpeg and verifies what came out."""

from __future__ import annotations

import logging
import tempfile
from contextlib import AsyncExitStack
from pathlib import Path
from typing import Any

from studio_common.errors import ServiceError
from studio_common.jobs import JobContext
from studio_common.media import MediaInfo, probe, run_ffmpeg_with_progress
from studio_common.storage import Storage

from video_renderer.graph import ResolvedInputs, build_plan
from video_renderer.schemas import RenderOutput, RenderRequest
from video_renderer.settings import VideoRendererSettings

logger = logging.getLogger(__name__)

CLIP_FORMATS = ("mov,mp4", "matroska,webm")
IMAGE_FORMATS = ("image2", "jpeg_pipe", "png_pipe", "webp_pipe")
IMAGE_CODECS = ("mjpeg", "png", "webp")


def _bad(code: str, message: str) -> ServiceError:
    return ServiceError(422, code, message)


def check_clip(info: MediaInfo, needed: float, settings: VideoRendererSettings, key: str) -> None:
    if not info.format_name.startswith(CLIP_FORMATS) or info.video is None:
        raise _bad("unsupported_input", f"'{key}' is not an MP4, MOV or WebM video.")
    if info.video.width * info.video.height > settings.max_input_pixels:
        raise _bad("input_too_large", f"'{key}' has too many pixels.")
    if info.duration > settings.max_input_seconds:
        raise _bad("input_too_long", f"'{key}' is too long.")
    if info.duration < needed - 0.1:
        raise _bad(
            "clip_too_short", f"'{key}' is {info.duration:.1f}s but {needed:.1f}s is needed."
        )


def check_image(info: MediaInfo, settings: VideoRendererSettings, key: str) -> None:
    if (
        info.format_name not in IMAGE_FORMATS
        or info.video is None
        or info.video.codec not in IMAGE_CODECS
    ):
        raise _bad("unsupported_input", f"'{key}' is not a JPEG, PNG or WebP image.")
    if info.video.width * info.video.height > settings.max_input_pixels:
        raise _bad("input_too_large", f"'{key}' has too many pixels.")


def check_music(
    info: MediaInfo, needed: float, loop: bool, settings: VideoRendererSettings
) -> None:
    if info.audio is None:
        raise _bad("unsupported_input", "The music file has no audio.")
    if info.duration > settings.max_music_seconds:
        raise _bad("input_too_long", "The music file is too long.")
    if not loop and info.duration < needed - 0.1:
        raise _bad("music_too_short", "The music is shorter than the video and looping is off.")


async def render(
    request: RenderRequest,
    ctx: JobContext,
    storage: Storage,
    settings: VideoRendererSettings,
) -> dict[str, Any]:
    await ctx.update("checking_inputs", 0.02)
    async with AsyncExitStack() as stack:
        segment_paths: list[Path] = []
        for segment in request.segments:
            path = await stack.enter_async_context(storage.local_copy(segment.key))
            info = await probe(path)
            if segment.kind == "clip":
                check_clip(info, segment.duration_seconds, settings, segment.key)
            else:
                check_image(info, settings, segment.key)
            segment_paths.append(path)

        logo_path = None
        if request.logo is not None:
            logo_path = await stack.enter_async_context(storage.local_copy(request.logo.key))
            check_image(await probe(logo_path), settings, request.logo.key)

        music_path = None
        if request.music is not None:
            music_path = await stack.enter_async_context(storage.local_copy(request.music.key))
            check_music(
                await probe(music_path), request.duration_seconds, request.music.loop, settings
            )

        with tempfile.TemporaryDirectory(prefix="render-") as directory:
            workdir = Path(directory)
            output = workdir / "out.mp4"
            plan = build_plan(
                request,
                ResolvedInputs(segment_paths, logo_path, music_path),
                output,
                workdir,
                settings.font_path,
                threads=settings.ffmpeg_threads,
            )

            async def progress(fraction: float) -> None:
                await ctx.update("rendering", 0.05 + 0.85 * fraction)

            await ctx.update("rendering", 0.05)
            await run_ffmpeg_with_progress(
                plan.args,
                total_seconds=plan.duration,
                on_progress=progress,
                timeout=settings.render_timeout_seconds,
            )
            await ctx.update("verifying", 0.92)
            info = await probe(output)
            _verify(info, request, plan.width, plan.height)
            await storage.store_file(output, request.output_key)

    assert info.video is not None  # noqa: S101  (checked in _verify)
    logger.info(
        "render_finished",
        extra={
            "segments": len(request.segments),
            "bytes": info.size_bytes,
            "seconds": info.duration,
        },
    )
    return RenderOutput(
        output_key=request.output_key,
        size_bytes=info.size_bytes,
        duration_seconds=round(info.duration, 3),
        width=info.video.width,
        height=info.video.height,
        fps=round(info.video.fps, 3),
        has_audio=info.audio is not None,
    ).model_dump()


def _verify(info: MediaInfo, request: RenderRequest, width: int, height: int) -> None:
    problems: list[str] = []
    if info.video is None:
        problems.append("no video stream")
    else:
        if (info.video.width, info.video.height) != (width, height):
            problems.append("wrong size")
        if abs(info.video.fps - request.fps) > 0.5:
            problems.append("wrong frame rate")
    if info.audio is None:
        problems.append("no audio stream")
    if abs(info.duration - request.duration_seconds) > 0.15:
        problems.append("wrong duration")
    if problems:
        logger.error("render_verification_failed", extra={"problems": problems})
        raise ServiceError(500, "postprocess_failed", "The rendered video did not pass checks.")
