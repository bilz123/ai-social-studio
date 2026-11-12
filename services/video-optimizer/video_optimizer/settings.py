from __future__ import annotations

from studio_common.config import ServiceSettings


class VideoOptimizerSettings(ServiceSettings):
    service_name: str = "video-optimizer"

    encode_timeout_seconds: float = 900.0
    ffmpeg_threads: int = 0  # 0 = let FFmpeg decide
    max_input_seconds: float = 300.0
    max_input_pixels: int = 4096 * 4096
    max_outputs: int = 6
    # Our own conservative ceiling for any delivered file (not a platform's published maximum).
    max_output_mb: int = 100
