"""Checks a finished video against a delivery profile."""

from __future__ import annotations

import re
import struct
from dataclasses import dataclass
from pathlib import Path

from studio_common.media import MediaInfo, run_ffmpeg_capture

from video_optimizer.schemas import Check, Profile, ValidationReport, VideoFacts


@dataclass(frozen=True)
class ProfileRules:
    aspect: float | None  # width / height, or None for "any"
    min_seconds: float
    max_seconds: float
    min_fps: float = 23.0
    max_fps: float = 60.5
    max_mb: int = 100


# These are OUR conservative delivery targets. Platforms change their published limits often, so
# treat them as safe defaults to review, not as the platforms' own maxima.
PROFILES: dict[Profile, ProfileRules] = {
    "master": ProfileRules(aspect=9 / 16, min_seconds=1, max_seconds=60),
    "instagram_reels": ProfileRules(aspect=9 / 16, min_seconds=3, max_seconds=90),
    "facebook_reels": ProfileRules(aspect=9 / 16, min_seconds=3, max_seconds=90),
    "youtube_shorts": ProfileRules(aspect=9 / 16, min_seconds=1, max_seconds=60),
    "youtube_video": ProfileRules(aspect=None, min_seconds=1, max_seconds=3600),
}


def faststart(path: Path) -> bool:
    """True if the `moov` index comes before the `mdat` data (the file can start playing early)."""
    with path.open("rb") as handle:
        handle.seek(0, 2)
        end = handle.tell()
        handle.seek(0)
        while handle.tell() + 8 <= end:
            header = handle.read(8)
            size, kind = struct.unpack(">I4s", header)
            if kind == b"moov":
                return True
            if kind == b"mdat":
                return False
            if size == 1:
                size = struct.unpack(">Q", handle.read(8))[0]
                handle.seek(-16, 1)
            elif size == 0:
                return False
            if size < 8:
                return False
            handle.seek(size - 8, 1)
    return False


def facts_from(info: MediaInfo, path: Path) -> VideoFacts:
    video, audio = info.video, info.audio
    return VideoFacts(
        container=info.format_name,
        video_codec=video.codec if video else None,
        profile=video.profile if video else None,
        pix_fmt=video.pix_fmt if video else None,
        width=video.width if video else None,
        height=video.height if video else None,
        fps=round(video.fps, 3) if video else None,
        audio_codec=audio.codec if audio else None,
        sample_rate=audio.sample_rate if audio else None,
        channels=audio.channels if audio else None,
        duration_seconds=round(info.duration, 3),
        size_bytes=info.size_bytes,
        bit_rate=info.bit_rate,
        faststart=faststart(path),
    )


def _check(name: str, passed: bool, expected: str, actual: str, severity: str = "error") -> Check:
    return Check(
        name=name,
        passed=passed,
        severity="error" if severity == "error" else "warning",
        expected=expected,
        actual=actual,
    )


def validate_facts(
    facts: VideoFacts,
    profile: Profile,
    *,
    expected_duration: float | None = None,
    max_mb: int | None = None,
) -> ValidationReport:
    rules = PROFILES[profile]
    limit_mb = min(max_mb or rules.max_mb, rules.max_mb)
    checks: list[Check] = [
        _check("container", facts.container.startswith("mov,mp4"), "mp4", facts.container),
        _check("video_codec", facts.video_codec == "h264", "h264", str(facts.video_codec)),
        _check("pixel_format", facts.pix_fmt == "yuv420p", "yuv420p", str(facts.pix_fmt)),
        _check("audio_codec", facts.audio_codec == "aac", "aac", str(facts.audio_codec)),
        _check("audio_sample_rate", facts.sample_rate == 48000, "48000 Hz", str(facts.sample_rate)),
        _check("audio_channels", facts.channels == 2, "2 (stereo)", str(facts.channels)),
        _check("faststart", facts.faststart, "moov before mdat", str(facts.faststart)),
    ]
    fps = facts.fps or 0.0
    checks.append(
        _check(
            "frame_rate", rules.min_fps <= fps <= rules.max_fps, f"{rules.min_fps:g}-60", f"{fps:g}"
        )
    )
    checks.append(
        _check(
            "duration",
            rules.min_seconds <= facts.duration_seconds <= rules.max_seconds,
            f"{rules.min_seconds:g}-{rules.max_seconds:g} s",
            f"{facts.duration_seconds:g} s",
        )
    )
    if expected_duration is not None:
        checks.append(
            _check(
                "expected_duration",
                abs(facts.duration_seconds - expected_duration) <= 0.1,
                f"{expected_duration:g} s (+/- 0.1)",
                f"{facts.duration_seconds:g} s",
            )
        )
    if rules.aspect is not None and facts.width and facts.height:
        ratio = facts.width / facts.height
        checks.append(
            _check(
                "aspect_ratio",
                abs(ratio - rules.aspect) <= 0.01,
                "9:16",
                f"{facts.width}x{facts.height}",
            )
        )
    size_mb = facts.size_bytes / (1024 * 1024)
    checks.append(
        _check("file_size", size_mb <= limit_mb, f"<= {limit_mb} MB", f"{size_mb:.1f} MB")
    )
    if facts.width and facts.height:
        even = facts.width % 2 == 0 and facts.height % 2 == 0
        checks.append(
            _check(
                "even_dimensions", even, "even width and height", f"{facts.width}x{facts.height}"
            )
        )
    return ValidationReport(
        valid=all(c.passed for c in checks if c.severity == "error"),
        profile=profile,
        checks=checks,
        facts=facts,
    )


_BLACK = re.compile(r"black_start:(\d+\.?\d*) black_end:(\d+\.?\d*)")
_FROZEN = re.compile(r"freeze_duration: (\d+\.?\d*)")


async def deep_checks(path: Path, duration: float) -> list[Check]:
    """Look for black or frozen sections. These are warnings: a dark scene can be intentional."""
    log = await run_ffmpeg_capture(
        ["-i", str(path), "-vf", "blackdetect=d=0.5:pix_th=0.05,freezedetect=n=-60dB:d=2",
         "-an", "-f", "null", "-"],
        timeout=300,
    )  # fmt: skip
    black = sum(float(e) - float(s) for s, e in _BLACK.findall(log))
    frozen = sum(float(d) for d in _FROZEN.findall(log))
    return [
        _check(
            "black_frames", black <= duration * 0.2, "< 20 % black", f"{black:.1f} s", "warning"
        ),
        _check(
            "frozen_frames", frozen <= duration * 0.2, "< 20 % frozen", f"{frozen:.1f} s", "warning"
        ),
    ]
