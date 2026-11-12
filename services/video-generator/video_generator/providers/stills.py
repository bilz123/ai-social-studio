"""Free "motion from stills" provider: cinematic camera moves over the customer's own photo.

No AI model, no GPU, no vendor account and no cost per video. FFmpeg crops the photo to the target
shape and moves a virtual camera over it (push in, pull out, pan or tilt, eased). Because every
pixel comes from the original photo, the product's shape, colours and logo cannot drift, which
generative models cannot promise. It is not generative: nothing in the picture itself moves.

The prompt only selects the camera move through keyword matching. Prompt text never reaches the
FFmpeg command line or filter string.
"""

from __future__ import annotations

import asyncio
import re
import tempfile
import uuid
from pathlib import Path

from studio_common.media import run_ffmpeg

from video_generator.providers.base import (
    ImageToVideoRequest,
    ProviderCapabilities,
    ProviderError,
    ProviderJob,
    ProviderStatus,
)

FPS = 30
_LONG_EDGE = {"9:16": (9, 16), "1:1": (1, 1), "16:9": (16, 9)}

_MOVES: tuple[tuple[str, re.Pattern[str]], ...] = (
    ("pan", re.compile(r"\b(orbit|pan|lateral|slide|parallax)\b", re.I)),
    ("pull_out", re.compile(r"\b(pull|reveal|zoom out|widen|retreat)\b", re.I)),
    ("tilt", re.compile(r"\b(tilt|rise|crane|ascend)\b", re.I)),
)


def choose_move(prompt: str) -> str:
    for name, pattern in _MOVES:
        if pattern.search(prompt):
            return name
    return "push_in"


def output_size(aspect_ratio: str, long_edge: int) -> tuple[int, int]:
    w, h = _LONG_EDGE[aspect_ratio]
    scale = long_edge / max(w, h)
    # Keep the short side at or below 1080 and both sides even.
    short = min(int(min(w, h) * scale), 1080)
    long = int(short * max(w, h) / min(w, h)) if min(w, h) != max(w, h) else short
    width, height = (short, long) if h >= w else (long, short)
    return width // 2 * 2, height // 2 * 2


def build_filter(move: str, width: int, height: int, frames: int) -> str:
    """Filter chain for one move. All numbers are computed here, none come from user text."""
    e = f"(pow(on/{frames},2)*(3-2*on/{frames}))"  # smoothstep ease in and out
    centre_x = "iw/2-(iw/zoom/2)"
    centre_y = "ih/2-(ih/zoom/2)"
    if move == "pull_out":
        z, x, y = f"1.18-0.18*{e}", centre_x, centre_y
    elif move == "pan":
        z, x, y = "1.14", f"(iw-iw/zoom)*{e}", centre_y
    elif move == "tilt":
        z, x, y = "1.14", centre_x, f"(ih-ih/zoom)*(1-{e})"
    else:
        z, x, y = f"1+0.18*{e}", centre_x, centre_y
    # Work from a 2x canvas so sub-pixel camera motion stays smooth, then zoompan scales down.
    return (
        f"scale={width * 2}:{height * 2}:force_original_aspect_ratio=increase:flags=lanczos,"
        f"crop={width * 2}:{height * 2},setsar=1,"
        f"zoompan=z='{z}':x='{x}':y='{y}':d={frames}:s={width}x{height}:fps={FPS},"
        "format=yuv420p"
    )


class StillsProvider:
    name = "stills"
    capabilities = ProviderCapabilities(
        max_seconds=10.0, aspect_ratios=tuple(_LONG_EDGE), cost_cents_per_second=0.0
    )

    def __init__(self, work_dir: Path | None = None, long_edge: int = 1920) -> None:
        self._dir = work_dir or Path(tempfile.mkdtemp(prefix="stills-provider-"))
        self._dir.mkdir(parents=True, exist_ok=True)
        self._long_edge = long_edge
        self._tasks: dict[str, asyncio.Task[None]] = {}
        self._errors: dict[str, ProviderError] = {}

    async def submit(self, request: ImageToVideoRequest) -> ProviderJob:
        job_id = "stills-" + uuid.uuid4().hex[:12]
        self._tasks[job_id] = asyncio.create_task(self._render(job_id, request))
        return ProviderJob(id=job_id, provider=self.name)

    async def _render(self, job_id: str, request: ImageToVideoRequest) -> None:
        try:
            width, height = output_size(request.aspect_ratio, self._long_edge)
            frames = max(round(request.duration_seconds * FPS), 2)
            graph = build_filter(choose_move(request.prompt), width, height, frames)
            await run_ffmpeg(
                ["-protocol_whitelist", "file", "-loop", "1", "-i", str(request.image_path),
                 "-vf", graph, "-frames:v", str(frames), "-an", "-c:v", "libx264",
                 "-preset", "veryfast", "-crf", "18", "-pix_fmt", "yuv420p",
                 str(self._dir / f"{job_id}.mp4")],
                timeout=300,
            )  # fmt: skip
        except asyncio.CancelledError:
            raise
        except Exception:
            self._errors[job_id] = ProviderError("render_failed", "Local render failed.")

    async def poll(self, job: ProviderJob) -> ProviderStatus:
        task = self._tasks.get(job.id)
        if task is None:
            return ProviderStatus(
                state="failed", error=ProviderError("unknown_job", "Unknown job.")
            )
        if not task.done():
            return ProviderStatus(state="running")
        if job.id in self._errors:
            return ProviderStatus(state="failed", error=self._errors[job.id])
        return ProviderStatus(
            state="succeeded", output_url=str(self._dir / f"{job.id}.mp4"), cost_cents=0
        )

    async def fetch(self, job: ProviderJob, status: ProviderStatus, destination: Path) -> None:
        if status.output_url is None:
            raise ProviderError("no_output", "No output to fetch.")
        await asyncio.to_thread(destination.write_bytes, Path(status.output_url).read_bytes())

    async def cancel(self, job: ProviderJob) -> None:
        task = self._tasks.get(job.id)
        if task is not None and not task.done():
            task.cancel()
