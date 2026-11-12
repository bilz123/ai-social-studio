"""The contract every external video provider implements.

A provider turns one image and a prompt into one short clip. Vendor-specific adapters (hosted
APIs, self-hosted models) implement `VideoProvider`; the rest of the service never changes.
"""

from __future__ import annotations

from dataclasses import dataclass
from pathlib import Path
from typing import Literal, Protocol


class ProviderError(Exception):
    """A provider-side failure. `retryable` failures may be retried or sent to the fallback."""

    def __init__(self, code: str, message: str, *, retryable: bool = False) -> None:
        super().__init__(message)
        self.code = code
        self.message = message
        self.retryable = retryable


class ProviderRejected(ProviderError):
    """The provider refused the content (policy). Never retried."""

    def __init__(self, message: str = "The provider rejected this content.") -> None:
        super().__init__("provider_rejected", message, retryable=False)


@dataclass(frozen=True)
class ProviderCapabilities:
    max_seconds: float
    aspect_ratios: tuple[str, ...]
    cost_cents_per_second: float = 0.0


@dataclass(frozen=True)
class ImageToVideoRequest:
    image_path: Path
    prompt: str
    negative_prompt: str
    duration_seconds: float
    aspect_ratio: str
    seed: int | None = None


@dataclass(frozen=True)
class ProviderJob:
    id: str
    provider: str


@dataclass(frozen=True)
class ProviderStatus:
    state: Literal["queued", "running", "succeeded", "failed"]
    output_url: str | None = None
    error: ProviderError | None = None
    cost_cents: float | None = None


class VideoProvider(Protocol):
    name: str
    capabilities: ProviderCapabilities

    async def submit(self, request: ImageToVideoRequest) -> ProviderJob: ...

    async def poll(self, job: ProviderJob) -> ProviderStatus: ...

    async def fetch(self, job: ProviderJob, status: ProviderStatus, destination: Path) -> None:
        """Download the finished clip to `destination`."""
        ...

    async def cancel(self, job: ProviderJob) -> None: ...
