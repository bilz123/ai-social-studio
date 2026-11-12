from __future__ import annotations

import re
from typing import Self

from pydantic import model_validator
from studio_common.config import ServiceSettings


class VideoRendererSettings(ServiceSettings):
    service_name: str = "video-renderer"

    # A bundled font keeps text rendering identical everywhere (installed in the Docker image).
    font_path: str = "/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf"
    render_timeout_seconds: float = 900.0
    ffmpeg_threads: int = 0  # 0 = let FFmpeg decide
    max_segments: int = 6
    max_input_seconds: float = 120.0
    max_input_pixels: int = 4096 * 4096
    max_music_seconds: float = 3600.0

    @model_validator(mode="after")
    def _font_path_is_plain(self) -> Self:
        # The path is placed inside an FFmpeg filter, so refuse anything but plain path characters.
        if not re.fullmatch(r"/[A-Za-z0-9_./\- ]+", self.font_path):
            raise ValueError("AI_FONT_PATH contains unsupported characters")
        return self
