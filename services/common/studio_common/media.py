"""FFmpeg / ffprobe helpers: bounded subprocess calls and a typed `probe` result."""

from __future__ import annotations

import asyncio
import json
import logging
import shutil
from collections.abc import Awaitable, Callable
from fractions import Fraction
from pathlib import Path

from pydantic import BaseModel

from studio_common.errors import ServiceError

logger = logging.getLogger(__name__)

_STDERR_TAIL = 600


class VideoStream(BaseModel):
    codec: str
    profile: str | None = None
    width: int
    height: int
    fps: float
    pix_fmt: str | None = None
    bit_rate: int | None = None


class AudioStream(BaseModel):
    codec: str
    sample_rate: int
    channels: int
    bit_rate: int | None = None


class MediaInfo(BaseModel):
    duration: float
    size_bytes: int
    format_name: str
    bit_rate: int | None = None
    video: VideoStream | None = None
    audio: AudioStream | None = None


def have_binary(name: str) -> bool:
    return shutil.which(name) is not None


class FFmpegCheck:
    name = "ffmpeg"

    async def check(self) -> None:
        for binary in ("ffmpeg", "ffprobe"):
            if not have_binary(binary):
                raise RuntimeError(f"{binary} is not installed")


async def _run(args: list[str], *, timeout: float, code: str, message: str) -> tuple[bytes, bytes]:
    try:
        process = await asyncio.create_subprocess_exec(
            *args,
            stdin=asyncio.subprocess.DEVNULL,
            stdout=asyncio.subprocess.PIPE,
            stderr=asyncio.subprocess.PIPE,
        )
    except FileNotFoundError as error:
        raise ServiceError(503, "ffmpeg_unavailable", "FFmpeg is not installed.") from error
    try:
        stdout, stderr = await asyncio.wait_for(process.communicate(), timeout=timeout)
    except TimeoutError as error:
        process.kill()
        await process.wait()
        raise ServiceError(500, code, f"{message} (timed out).", retryable=True) from error
    except asyncio.CancelledError:
        process.kill()
        await process.wait()
        raise
    if process.returncode != 0:
        tail = stderr.decode(errors="replace")[-_STDERR_TAIL:]
        logger.error("ffmpeg_failed", extra={"command": args[0], "stderr_tail": tail})
        raise ServiceError(500, code, message)
    return stdout, stderr


async def run_ffmpeg(args: list[str], *, timeout: float = 300.0) -> None:
    """Run ffmpeg with `args` (without the program name). Errors never expose stderr to callers."""
    await _run(
        ["ffmpeg", "-hide_banner", "-nostdin", "-y", "-loglevel", "error", *args],
        timeout=timeout,
        code="postprocess_failed",
        message="Video processing failed.",
    )


async def run_ffmpeg_capture(args: list[str], *, timeout: float = 120.0) -> str:
    """Run ffmpeg and return stderr (used by analysis filters such as blackdetect)."""
    _, stderr = await _run(
        ["ffmpeg", "-hide_banner", "-nostdin", "-y", "-loglevel", "info", *args],
        timeout=timeout,
        code="postprocess_failed",
        message="Video analysis failed.",
    )
    return stderr.decode(errors="replace")


def _fps(raw: str | None) -> float:
    if not raw or raw == "0/0":
        return 0.0
    try:
        return float(Fraction(raw))
    except (ValueError, ZeroDivisionError):
        return 0.0


def _int(value: object) -> int | None:
    try:
        return int(str(value))
    except (TypeError, ValueError):
        return None


async def probe(path: Path, *, timeout: float = 30.0) -> MediaInfo:
    """Describe a media file with ffprobe. Raises 422 `invalid_media` for unreadable media."""
    try:
        stdout, _ = await _run(
            [
                "ffprobe",
                "-v",
                "error",
                "-print_format",
                "json",
                "-show_format",
                "-show_streams",
                str(path),
            ],
            timeout=timeout,
            code="invalid_media",
            message="The file is not valid media.",
        )
    except ServiceError as error:
        if error.code == "invalid_media":
            raise ServiceError(422, "invalid_media", "The file is not valid media.") from error
        raise
    data = json.loads(stdout or b"{}")
    fmt = data.get("format", {})
    video = audio = None
    for stream in data.get("streams", []):
        if stream.get("codec_type") == "video" and video is None and not _is_cover(stream):
            video = VideoStream(
                codec=stream.get("codec_name", "unknown"),
                profile=stream.get("profile"),
                width=int(stream.get("width", 0)),
                height=int(stream.get("height", 0)),
                fps=_fps(stream.get("avg_frame_rate") or stream.get("r_frame_rate")),
                pix_fmt=stream.get("pix_fmt"),
                bit_rate=_int(stream.get("bit_rate")),
            )
        elif stream.get("codec_type") == "audio" and audio is None:
            audio = AudioStream(
                codec=stream.get("codec_name", "unknown"),
                sample_rate=_int(stream.get("sample_rate")) or 0,
                channels=int(stream.get("channels", 0)),
                bit_rate=_int(stream.get("bit_rate")),
            )
    return MediaInfo(
        duration=float(fmt.get("duration") or 0.0),
        size_bytes=_int(fmt.get("size")) or path.stat().st_size,
        format_name=str(fmt.get("format_name", "")),
        bit_rate=_int(fmt.get("bit_rate")),
        video=video,
        audio=audio,
    )


def _is_cover(stream: dict[str, object]) -> bool:
    disposition = stream.get("disposition")
    return isinstance(disposition, dict) and bool(disposition.get("attached_pic"))


async def run_ffmpeg_with_progress(
    args: list[str],
    *,
    total_seconds: float,
    on_progress: Callable[[float], Awaitable[None]],
    timeout: float = 600.0,
) -> None:
    """Run ffmpeg and report progress (0-1) from its `-progress` output. Errors hide stderr."""
    command = [
        "ffmpeg", "-hide_banner", "-nostdin", "-y", "-loglevel", "error",
        "-progress", "pipe:1", "-nostats", *args,
    ]  # fmt: skip
    try:
        process = await asyncio.create_subprocess_exec(
            *command,
            stdin=asyncio.subprocess.DEVNULL,
            stdout=asyncio.subprocess.PIPE,
            stderr=asyncio.subprocess.PIPE,
        )
    except FileNotFoundError as error:
        raise ServiceError(503, "ffmpeg_unavailable", "FFmpeg is not installed.") from error
    stdout, err_stream = process.stdout, process.stderr
    if stdout is None or err_stream is None:
        raise ServiceError(500, "postprocess_failed", "Video processing failed.")

    async def read_progress() -> None:
        async for raw in stdout:
            line = raw.decode(errors="replace").strip()
            if line.startswith("out_time_us="):
                try:
                    seconds = int(line.split("=", 1)[1]) / 1_000_000
                except ValueError:
                    continue
                await on_progress(max(0.0, min(1.0, seconds / total_seconds)))

    async def finish() -> bytes:
        _, stderr_bytes = await asyncio.gather(read_progress(), err_stream.read())
        await process.wait()
        return bytes(stderr_bytes)

    try:
        stderr = await asyncio.wait_for(finish(), timeout=timeout)
    except TimeoutError as error:
        process.kill()
        await process.wait()
        raise ServiceError(
            500, "postprocess_failed", "Video processing timed out.", retryable=True
        ) from error
    except asyncio.CancelledError:
        process.kill()
        await process.wait()
        raise
    if process.returncode != 0:
        tail = stderr.decode(errors="replace")[-_STDERR_TAIL:]
        logger.error("ffmpeg_failed", extra={"command": "ffmpeg", "stderr_tail": tail})
        raise ServiceError(500, "postprocess_failed", "Video processing failed.")
