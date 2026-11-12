from __future__ import annotations

from typing import Self

from pydantic import SecretStr, model_validator
from studio_common.config import ServiceSettings, has_value


class VideoGeneratorSettings(ServiceSettings):
    service_name: str = "video-generator"

    # Which provider to use first, and which to fall back to. `fake` renders a local test clip
    # with FFmpeg (development and CI only; refused in staging/production).
    video_provider_primary: str = "fake"
    video_provider_fallback: str | None = None

    # The generic REST provider. Credentials come from the environment, never from code.
    video_http_base_url: str | None = None
    video_http_api_key: SecretStr | None = None
    video_http_submit_path: str = "/jobs"
    video_http_status_path: str = "/jobs/{id}"
    video_http_cancel_path: str = "/jobs/{id}"
    video_http_max_seconds: float = 10.0
    video_http_cost_cents_per_second: float = 0.0
    # Hosts the provider may serve finished videos from (empty: any public https host).
    video_http_download_hosts: str = ""

    clip_timeout_seconds: float = 480.0
    poll_initial_seconds: float = 2.0
    poll_max_seconds: float = 15.0
    max_attempts_per_clip: int = 2
    max_cost_cents_default: int = 150
    max_provider_concurrency: int = 4
    breaker_failures: int = 5
    breaker_cooldown_seconds: float = 60.0
    max_clip_bytes: int = 200 * 1024 * 1024

    @model_validator(mode="after")
    def _providers_are_usable(self) -> Self:
        names = {self.video_provider_primary, self.video_provider_fallback} - {None}
        if self.environment in ("staging", "production") and "fake" in names:
            raise ValueError("The fake video provider cannot be used in staging or production")
        if "http" in names and not (
            self.video_http_base_url and has_value(self.video_http_api_key)
        ):
            raise ValueError("AI_VIDEO_HTTP_BASE_URL and AI_VIDEO_HTTP_API_KEY are required")
        return self
