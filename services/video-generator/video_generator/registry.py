from __future__ import annotations

from pathlib import Path

import httpx

from video_generator.providers.base import VideoProvider
from video_generator.providers.fake import FakeProvider
from video_generator.providers.http import HttpProvider
from video_generator.providers.stills import StillsProvider
from video_generator.settings import VideoGeneratorSettings


def build_providers(
    settings: VideoGeneratorSettings,
    *,
    work_dir: Path | None = None,
    http_client: httpx.AsyncClient | None = None,
) -> dict[str, VideoProvider]:
    """Create only the providers that are named in the configuration."""
    wanted = {settings.video_provider_primary, settings.video_provider_fallback} - {None}
    providers: dict[str, VideoProvider] = {}
    if "fake" in wanted:
        providers["fake"] = FakeProvider(work_dir)
    if "stills" in wanted:
        providers["stills"] = StillsProvider(work_dir, settings.stills_long_edge)
    if "http" in wanted:
        providers["http"] = HttpProvider(settings, http_client)
    unknown = wanted - set(providers) - {None}
    if unknown:
        raise ValueError(f"Unknown video provider(s): {', '.join(sorted(str(u) for u in unknown))}")
    return providers
