"""Request and response contracts. Field names here are the public API."""

from __future__ import annotations

import re
from typing import Literal, Self

from pydantic import BaseModel, ConfigDict, Field, field_validator, model_validator
from studio_common.jobs import JobError, JobStatus

AspectRatio = Literal["9:16", "1:1", "16:9"]
Quality = Literal["draft", "standard", "high"]
Transition = Literal[
    "fade", "fadeblack", "fadewhite", "dissolve", "wipeleft", "wiperight",
    "slideleft", "slideright", "smoothleft", "smoothright", "circleopen",
]  # fmt: skip
ZoomStyle = Literal["in", "out", "none"]
Corner = Literal["top_left", "top_right", "bottom_left", "bottom_right"]
Position = Literal["top", "center", "bottom"]

_KEY = r"^[A-Za-z0-9][A-Za-z0-9._\-/]{0,254}$"
_COLOR = re.compile(r"^#[0-9a-fA-F]{6}$")
# Scripts that need text shaping (joining, reordering). Plain FFmpeg text drawing would produce
# broken output for them, so they are refused rather than rendered wrongly.
_UNSUPPORTED_SCRIPTS = re.compile("[֐-ࣿऀ-෿฀-໿ༀ-࿿က-႟יִ-﷿ﹰ-﻾]")


class Zoom(BaseModel):
    model_config = ConfigDict(extra="forbid")

    style: ZoomStyle = "in"
    amount: float = Field(default=0.12, ge=0, le=0.4, description="0.12 = 12 % closer.")
    focus_x: float = Field(default=0.5, ge=0, le=1, description="Where to zoom towards (0-1).")
    focus_y: float = Field(default=0.5, ge=0, le=1)


class Segment(BaseModel):
    model_config = ConfigDict(extra="forbid")

    kind: Literal["clip", "image"]
    key: str = Field(pattern=_KEY)
    duration_seconds: float = Field(gt=0.5, le=30)
    zoom: Zoom = Field(default_factory=Zoom)


class TransitionSpec(BaseModel):
    model_config = ConfigDict(extra="forbid")

    type: Transition = "fade"
    duration_seconds: float = Field(default=1.0, ge=0.1, le=2.0)


class TextOverlay(BaseModel):
    model_config = ConfigDict(extra="forbid")

    text: str = Field(min_length=1, max_length=80)
    start_seconds: float = Field(ge=0)
    end_seconds: float = Field(gt=0)
    position: Position = "bottom"
    font_size: int = Field(default=64, ge=24, le=140, description="Pixels at full 1080-wide size.")
    color: str = "#ffffff"
    background: bool = True

    @field_validator("color")
    @classmethod
    def _color(cls, value: str) -> str:
        if not _COLOR.match(value):
            raise ValueError("color must look like #rrggbb")
        return value

    @field_validator("text")
    @classmethod
    def _text(cls, value: str) -> str:
        cleaned = "".join(c for c in value if c.isprintable()).strip()
        if not cleaned:
            raise ValueError("text is empty")
        if _UNSUPPORTED_SCRIPTS.search(cleaned):
            raise ValueError(
                "unsupported_text_script: right-to-left and complex scripts are not supported yet"
            )
        return cleaned

    @model_validator(mode="after")
    def _times(self) -> Self:
        if self.end_seconds <= self.start_seconds:
            raise ValueError("end_seconds must be after start_seconds")
        return self


class Logo(BaseModel):
    model_config = ConfigDict(extra="forbid")

    key: str = Field(pattern=_KEY)
    corner: Corner = "top_right"
    width_percent: float = Field(default=14, ge=4, le=40, description="Of the video width.")
    opacity: float = Field(default=0.9, ge=0.1, le=1)
    margin_percent: float = Field(default=5, ge=1, le=15)
    start_seconds: float | None = Field(default=None, ge=0)
    end_seconds: float | None = Field(default=None, gt=0)


class Music(BaseModel):
    model_config = ConfigDict(extra="forbid")

    key: str = Field(pattern=_KEY)
    volume: float = Field(default=0.8, ge=0, le=1)
    fade_in_seconds: float = Field(default=0.5, ge=0, le=5)
    fade_out_seconds: float = Field(default=1.0, ge=0, le=5)
    normalize: bool = Field(default=True, description="Even out loudness to -16 LUFS first.")
    loop: bool = True


class Grade(BaseModel):
    model_config = ConfigDict(extra="forbid")

    contrast: float = Field(default=1.05, ge=0.7, le=1.5)
    saturation: float = Field(default=1.08, ge=0.5, le=1.6)
    vignette: bool = True


class RenderRequest(BaseModel):
    model_config = ConfigDict(extra="forbid")

    idempotency_key: str | None = Field(default=None, pattern=r"^[A-Za-z0-9_-]{1,64}$")
    output_key: str = Field(pattern=_KEY, description="Storage key for the MP4 (must end in .mp4).")
    aspect_ratio: AspectRatio = "9:16"
    resolution: Literal["full", "preview"] = Field(
        default="full", description="`preview` renders at half size for quick approval previews."
    )
    fps: Literal[24, 25, 30, 60] = 30
    duration_seconds: float = Field(default=10.0, gt=1, le=60)
    quality: Quality = "standard"
    segments: list[Segment] = Field(min_length=1, max_length=6)
    transition: TransitionSpec = Field(default_factory=TransitionSpec)
    grade: Grade | None = Field(default_factory=Grade)
    overlays: list[TextOverlay] = Field(default_factory=list, max_length=4)
    logo: Logo | None = None
    music: Music | None = None

    @field_validator("output_key")
    @classmethod
    def _mp4(cls, value: str) -> str:
        if not value.endswith(".mp4"):
            raise ValueError("output_key must end in .mp4")
        return value

    @model_validator(mode="after")
    def _timeline_adds_up(self) -> Self:
        n = len(self.segments)
        fade = self.transition.duration_seconds if n > 1 else 0.0
        total = sum(s.duration_seconds for s in self.segments) - fade * (n - 1)
        if abs(total - self.duration_seconds) > 0.05:
            raise ValueError(
                f"segments add up to {total:.2f}s after transitions, "
                f"but duration_seconds is {self.duration_seconds:.2f}s"
            )
        if n > 1:
            shortest = min(s.duration_seconds for s in self.segments)
            if fade >= shortest:
                raise ValueError("the transition must be shorter than every segment")
        for overlay in self.overlays:
            if overlay.end_seconds > self.duration_seconds + 0.001:
                raise ValueError("an overlay ends after the video does")
        return self


class RenderOutput(BaseModel):
    output_key: str
    size_bytes: int
    duration_seconds: float
    width: int
    height: int
    fps: float
    has_audio: bool


class RenderView(BaseModel):
    id: str
    status: JobStatus
    stage: str | None
    progress: float
    result: RenderOutput | None
    error: JobError | None
    created_at: str
    updated_at: str
