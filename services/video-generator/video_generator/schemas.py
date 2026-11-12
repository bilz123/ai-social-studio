"""Request and response contracts. Field names here are the public API."""

from __future__ import annotations

from typing import Literal, Self

from pydantic import BaseModel, ConfigDict, Field, model_validator
from studio_common.jobs import JobError, JobStatus

AspectRatio = Literal["9:16", "1:1", "16:9"]
_ID = r"^[A-Za-z0-9_-]{1,64}$"
_PREFIX = r"^[A-Za-z0-9][A-Za-z0-9_\-/]{0,200}$"


class Shot(BaseModel):
    model_config = ConfigDict(extra="forbid")

    shot_id: str = Field(pattern=r"^[a-z0-9_-]{1,32}$")
    image_key: str = Field(max_length=255, description="Storage key of the source image.")
    prompt: str = Field(min_length=1, max_length=1500)
    negative_prompt: str = Field(default="", max_length=800)
    duration_seconds: float = Field(gt=0, le=10, description="Seconds used in the final video.")
    request_seconds: float | None = Field(
        default=None, gt=0, le=12, description="Seconds to ask the provider for (>= duration)."
    )

    @property
    def ask_seconds(self) -> float:
        return max(self.request_seconds or self.duration_seconds, self.duration_seconds)


class TimelineSpec(BaseModel):
    model_config = ConfigDict(extra="forbid")

    total_duration_seconds: float = Field(default=10.0, gt=0, le=60)
    crossfade_seconds: float = Field(default=1.0, ge=0, le=3)


class GenerationRequest(BaseModel):
    model_config = ConfigDict(extra="forbid")

    idempotency_key: str | None = Field(default=None, pattern=_ID)
    aspect_ratio: AspectRatio = "9:16"
    shots: list[Shot] = Field(min_length=1, max_length=4)
    output_prefix: str = Field(
        pattern=_PREFIX, description="Clips are stored as <prefix>/<shot_id>.mp4"
    )
    provider: str | None = Field(default=None, pattern=r"^[a-z0-9_-]{1,32}$")
    max_cost_cents: int | None = Field(default=None, ge=0)
    seed: int | None = Field(default=None, ge=0, le=2**31 - 1)
    timeline: TimelineSpec | None = Field(
        default=None,
        description="If given, the shots must add up to this length once crossfades are removed.",
    )

    @model_validator(mode="after")
    def _consistent(self) -> Self:
        ids = [s.shot_id for s in self.shots]
        if len(set(ids)) != len(ids):
            raise ValueError("shot_id values must be unique")
        if self.timeline is not None:
            total = sum(s.duration_seconds for s in self.shots)
            total -= self.timeline.crossfade_seconds * (len(self.shots) - 1)
            if abs(total - self.timeline.total_duration_seconds) > 0.05:
                raise ValueError(
                    f"shots add up to {total:.2f}s, but the timeline needs "
                    f"{self.timeline.total_duration_seconds:.2f}s"
                )
        return self


class ClipResult(BaseModel):
    shot_id: str
    storage_key: str
    provider: str
    provider_job_id: str
    attempts: int
    duration_seconds: float
    width: int
    height: int
    cost_cents: float


class ShotProgress(BaseModel):
    provider: str | None = None
    provider_job_id: str | None = None
    state: str = "pending"
    attempts: int = 0
    error_code: str | None = None


class GenerationResult(BaseModel):
    clips: list[ClipResult]
    total_cost_cents: float
    attempted_provider_jobs: list[str] = Field(
        description="Every external job id created, including failed attempts."
    )


class GenerationView(BaseModel):
    id: str
    status: JobStatus
    stage: str | None
    progress: float
    shots: dict[str, ShotProgress]
    result: GenerationResult | None
    error: JobError | None
    created_at: str
    updated_at: str


class ProviderInfo(BaseModel):
    name: str
    max_seconds: float
    aspect_ratios: list[str]
    cost_cents_per_second: float
    role: Literal["primary", "fallback", "available"]
