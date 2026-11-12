from __future__ import annotations

from pydantic import SecretStr
from studio_common.config import ServiceSettings


class ImageAnalyzerSettings(ServiceSettings):
    service_name: str = "image-analyzer"

    max_images: int = 10
    max_image_bytes: int = 20 * 1024 * 1024
    max_pixels: int = 36_000_000

    # Optional vision model (any OpenAI-compatible chat-completions endpoint). Without it the
    # service still works and reports what it can measure itself. Never hardcode these values.
    vision_base_url: str | None = None
    vision_model: str | None = None
    vision_api_key: SecretStr | None = None
    vision_timeout_seconds: float = 30.0

    @property
    def vision_enabled(self) -> bool:
        return bool(self.vision_base_url and self.vision_model and self.vision_api_key)
