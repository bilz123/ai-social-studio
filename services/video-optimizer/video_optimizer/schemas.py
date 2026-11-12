"""Request and response contracts. Field names here are the public API."""

from __future__ import annotations

from typing import Literal, Self

from pydantic import BaseModel, ConfigDict, Field, model_validator
from studio_common.jobs import JobError, JobStatus

AspectRatio = Literal["9:16", "1:1", "4:5", "16:9"]
Profile = Literal["master", "instagram_reels", "facebook_reels", "youtube_shorts", "youtube_video"]
_KEY = r"^[A-Za-z0-9][A-Za-z0-9._\-/]{0,254}$"
_NAME = r"^[a-z0-9_-]{1,32}$"


class ValidateRequest(BaseModel):
    model_config = ConfigDict(extra="forbid")

    video_key: str = Field(pattern=_KEY)
    profile: Profile = "master"
    expected_duration_seconds: float | None = Field(default=None, gt=0, le=600)
    deep: bool = Field(default=False, description="Also look for black and frozen sections.")


class Check(BaseModel):
    name: str
    passed: bool
    severity: Literal["error", "warning"]
    expected: str
    actual: str


class VideoFacts(BaseModel):
    container: str
    video_codec: str | None
    profile: str | None
    pix_fmt: str | None
    width: int | None
    height: int | None
    fps: float | None
    audio_codec: str | None
    sample_rate: int | None
    channels: int | None
    duration_seconds: float
    size_bytes: int
    bit_rate: int | None
    faststart: bool


class ValidationReport(BaseModel):
    valid: bool = Field(description="True when no check with severity `error` failed.")
    profile: Profile
    checks: list[Check]
    facts: VideoFacts


class OutputSpec(BaseModel):
    model_config = ConfigDict(extra="forbid")

    name: str = Field(pattern=_NAME)
    aspect_ratio: AspectRatio = "9:16"
    mode: Literal["crop", "pad_blur"] = Field(
        default="crop", description="How to fit the source into a different shape."
    )
    focus_x: float = Field(default=0.5, ge=0, le=1, description="Crop focus, left to right.")
    max_size_mb: float | None = Field(default=None, gt=0.2, le=500)
    crf: int = Field(default=23, ge=16, le=32)
    preset: Literal["veryfast", "medium", "slow"] = "medium"
    profile: Profile | None = Field(default=None, description="Validate the result against this.")


class ThumbnailSpec(BaseModel):
    model_config = ConfigDict(extra="forbid")

    name: str = Field(pattern=_NAME)
    aspect_ratio: AspectRatio = "9:16"
    width: int | None = Field(default=None, ge=240, le=2160)


class ThumbnailOptions(BaseModel):
    model_config = ConfigDict(extra="forbid")

    at_seconds: float | None = Field(
        default=None, ge=0, description="Use this moment. Omit to pick the sharpest frame."
    )
    candidates: int = Field(default=8, ge=3, le=16)
    items: list[ThumbnailSpec] = Field(
        default_factory=lambda: [ThumbnailSpec(name="cover")], max_length=4
    )


class OptimizeRequest(BaseModel):
    model_config = ConfigDict(extra="forbid")

    idempotency_key: str | None = Field(default=None, pattern=r"^[A-Za-z0-9_-]{1,64}$")
    video_key: str = Field(pattern=_KEY)
    output_prefix: str = Field(pattern=r"^[A-Za-z0-9][A-Za-z0-9_\-/]{0,200}$")
    outputs: list[OutputSpec] = Field(min_length=1, max_length=6)
    thumbnails: ThumbnailOptions | None = Field(default_factory=ThumbnailOptions)

    @model_validator(mode="after")
    def _unique_names(self) -> Self:
        names = [o.name for o in self.outputs]
        if len(set(names)) != len(names):
            raise ValueError("output names must be unique")
        if self.thumbnails:
            thumbs = [t.name for t in self.thumbnails.items]
            if len(set(thumbs)) != len(thumbs):
                raise ValueError("thumbnail names must be unique")
        return self


class OutputResult(BaseModel):
    name: str
    storage_key: str
    aspect_ratio: AspectRatio
    width: int
    height: int
    size_bytes: int
    duration_seconds: float
    bit_rate: int | None
    original_size_bytes: int
    attempts: int
    validation: ValidationReport | None


class ThumbnailResult(BaseModel):
    name: str
    storage_key: str
    width: int
    height: int
    size_bytes: int
    at_seconds: float


class OptimizeResult(BaseModel):
    source: VideoFacts
    outputs: list[OutputResult]
    thumbnails: list[ThumbnailResult]


class OptimizeView(BaseModel):
    id: str
    status: JobStatus
    stage: str | None
    progress: float
    result: OptimizeResult | None
    error: JobError | None
    created_at: str
    updated_at: str
