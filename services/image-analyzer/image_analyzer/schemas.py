"""Request and response contracts. Field names here are the public API."""

from __future__ import annotations

from typing import Literal

from pydantic import BaseModel, ConfigDict, Field

ShotType = Literal["front", "detail", "lifestyle", "flat"]
Risk = Literal[
    "low_resolution",
    "blurry",
    "too_dark",
    "overexposed",
    "subject_touches_edge",
    "subject_lost_in_9_16_crop",
    "has_transparency",
    "animated",
]


class ImageRef(BaseModel):
    model_config = ConfigDict(extra="forbid")

    media_id: str = Field(
        pattern=r"^[A-Za-z0-9_-]{1,64}$", description="Caller's id for the image."
    )
    storage_key: str = Field(max_length=255, description="Key of the original in shared storage.")


class Brief(BaseModel):
    model_config = ConfigDict(extra="forbid")

    title: str | None = Field(default=None, max_length=160)
    description: str | None = Field(default=None, max_length=2000)


class AnalyzeRequest(BaseModel):
    model_config = ConfigDict(extra="forbid")

    images: list[ImageRef] = Field(min_length=1, max_length=10)
    brief: Brief | None = None
    use_vision: bool = Field(default=True, description="Ask the vision model if one is configured.")


class NamedColor(BaseModel):
    hex: str
    name: str
    share: float = Field(ge=0, le=1, description="Fraction of the image, 0-1.")


class Quality(BaseModel):
    score: float = Field(ge=0, le=1)
    sharpness: float = Field(description="Variance of the Laplacian on a 256 px copy.")
    brightness: float = Field(ge=0, le=1)
    contrast: float = Field(ge=0, le=1)


class Box(BaseModel):
    x: float
    y: float
    w: float
    h: float


class Background(BaseModel):
    kind: Literal["white", "solid", "complex"]
    hex: str | None = None


class Subject(BaseModel):
    box: Box = Field(description="Normalised 0-1 bounding box of the main subject.")
    coverage: float = Field(ge=0, le=1, description="Share of the frame the box covers.")
    centered: bool


class ImageAnalysis(BaseModel):
    media_id: str
    format: str
    width: int
    height: int
    aspect_ratio: float
    sha256: str = Field(description="Hash of the original bytes; the file itself is never changed.")
    quality: Quality
    colors: list[NamedColor]
    background: Background
    subject: Subject
    shot_type: ShotType
    usable_as_first_frame: bool
    risks: list[Risk]


class Product(BaseModel):
    category: str | None = None
    name: str | None = None
    materials: list[str] = Field(default_factory=list)
    colors: list[str] = Field(default_factory=list)
    key_features: list[str] = Field(default_factory=list)
    visible_text: list[str] = Field(default_factory=list)
    has_logo: bool | None = None
    has_people: bool | None = None
    source: Literal["heuristic", "vision"]
    confidence: float = Field(ge=0, le=1)


class Preservation(BaseModel):
    original_modified: Literal[False] = False
    hero_media_id: str | None = Field(description="Best image to start the video from.")
    must_preserve: list[str]
    negative_prompt_terms: list[str]


class AnalyzeResponse(BaseModel):
    images: list[ImageAnalysis]
    product: Product
    preservation: Preservation
    warnings: list[str] = Field(default_factory=list)
