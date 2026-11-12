from __future__ import annotations

import asyncio
import shutil
import subprocess
from collections import deque
from pathlib import Path

from PIL import Image

from video_generator.providers.base import (
    ImageToVideoRequest,
    ProviderCapabilities,
    ProviderError,
    ProviderJob,
    ProviderRejected,
    ProviderStatus,
)

_CACHE: dict[float, Path] = {}


def make_clip(path: Path, seconds: float, *, size: str = "180x320") -> Path:
    subprocess.run(
        ["ffmpeg", "-y", "-loglevel", "error", "-f", "lavfi", "-i",
         f"testsrc=size={size}:rate=30:duration={seconds}", "-pix_fmt", "yuv420p", str(path)],
        check=True,
    )  # fmt: skip
    return path


def make_image(path: Path) -> Path:
    path.parent.mkdir(parents=True, exist_ok=True)
    Image.new("RGB", (640, 640), (40, 90, 200)).save(path, "JPEG")
    return path


class ScriptedProvider:
    """A provider whose behaviour is scripted per submit call, for failure-path tests."""

    def __init__(self, name: str, script: list[str], tmp: Path, *, max_seconds: float = 10.0,
                 cost_per_second: float = 0.0, polls_before_done: int = 1) -> None:  # fmt: skip
        self.name = name
        self.capabilities = ProviderCapabilities(
            max_seconds=max_seconds,
            aspect_ratios=("9:16", "1:1", "16:9"),
            cost_cents_per_second=cost_per_second,
        )
        self._script = deque(script)
        self._tmp = tmp
        self._polls = polls_before_done
        self._seen: dict[str, int] = {}
        self._behaviour: dict[str, str] = {}
        self._duration: dict[str, float] = {}
        self.submitted: list[str] = []
        self.cancelled: list[str] = []
        self.requests: list[ImageToVideoRequest] = []
        self.calls = 0

    async def submit(self, request: ImageToVideoRequest) -> ProviderJob:
        self.calls += 1
        behaviour = self._script.popleft() if self._script else "ok"
        if behaviour == "submit_error":
            raise ProviderError("provider_unavailable", "down", retryable=True)
        if behaviour == "submit_auth":
            raise ProviderError("provider_auth_failed", "bad key")
        job_id = f"{self.name}-{self.calls}"
        self._behaviour[job_id] = behaviour
        self._duration[job_id] = request.duration_seconds
        self.submitted.append(job_id)
        self.requests.append(request)
        return ProviderJob(id=job_id, provider=self.name)

    async def poll(self, job: ProviderJob) -> ProviderStatus:
        behaviour = self._behaviour[job.id]
        if behaviour == "stall":
            return ProviderStatus(state="running")
        self._seen[job.id] = self._seen.get(job.id, 0) + 1
        if self._seen[job.id] <= self._polls:
            return ProviderStatus(state="queued" if self._seen[job.id] == 1 else "running")
        if behaviour == "fail_retryable":
            return ProviderStatus(
                state="failed", error=ProviderError("provider_failed", "x", retryable=True)
            )
        if behaviour == "fail_permanent":
            return ProviderStatus(
                state="failed", error=ProviderError("provider_invalid_request", "x")
            )
        if behaviour == "reject":
            return ProviderStatus(state="failed", error=ProviderRejected())
        return ProviderStatus(state="succeeded", output_url="memory", cost_cents=7.5)

    async def fetch(self, job: ProviderJob, status: ProviderStatus, destination: Path) -> None:
        behaviour = self._behaviour[job.id]
        if behaviour == "garbage":
            destination.write_bytes(b"not a video")
            return
        seconds = 0.5 if behaviour == "short" else min(self._duration[job.id], 2.0)
        # Real clips are made once and reused; "ok" clips are 2s so QA needs durations <= ~2s.
        cached = _CACHE.get(seconds)
        if cached is None or not cached.exists():
            cached = make_clip(self._tmp / f"cache-{seconds}.mp4", seconds)
            _CACHE[seconds] = cached
        await asyncio.to_thread(shutil.copyfile, cached, destination)

    async def cancel(self, job: ProviderJob) -> None:
        self.cancelled.append(job.id)
