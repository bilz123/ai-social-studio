"""A local stand-in provider: renders a slow zoom over the image with FFmpeg.

It exists so the whole pipeline can run in development and CI without paying a vendor. It is
refused in staging and production by the settings validator.
"""

from __future__ import annotations

import asyncio
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

_SIZES = {"9:16": (360, 640), "1:1": (480, 480), "16:9": (640, 360)}


class FakeProvider:
    name = "fake"
    capabilities = ProviderCapabilities(
        max_seconds=10.0, aspect_ratios=tuple(_SIZES), cost_cents_per_second=0.0
    )

    def __init__(self, work_dir: Path | None = None, latency_seconds: float = 0.0) -> None:
        self._dir = work_dir or Path(tempfile.mkdtemp(prefix="fake-provider-"))
        self._latency = latency_seconds
        self._tasks: dict[str, asyncio.Task[None]] = {}
        self._errors: dict[str, ProviderError] = {}

    async def submit(self, request: ImageToVideoRequest) -> ProviderJob:
        job_id = "fake-" + uuid.uuid4().hex[:12]
        self._tasks[job_id] = asyncio.create_task(self._render(job_id, request))
        return ProviderJob(id=job_id, provider=self.name)

    async def _render(self, job_id: str, request: ImageToVideoRequest) -> None:
        try:
            await asyncio.sleep(self._latency)
            width, height = _SIZES[request.aspect_ratio]
            frames = int(request.duration_seconds * 30)
            graph = (
                f"scale={width * 2}:{height * 2}:force_original_aspect_ratio=increase,"
                f"crop={width * 2}:{height * 2},"
                f"zoompan=z='min(zoom+0.0009,1.25)':d={frames}:s={width}x{height}:fps=30,"
                "format=yuv420p"
            )
            await run_ffmpeg(
                ["-i", str(request.image_path), "-vf", graph, "-frames:v", str(frames),
                 "-c:v", "libx264", "-preset", "ultrafast", str(self._dir / f"{job_id}.mp4")],
                timeout=120,
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
