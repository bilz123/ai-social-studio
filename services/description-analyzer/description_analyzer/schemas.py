"""Request and response contracts. Field names here are the public API."""

from __future__ import annotations

from typing import Literal

from pydantic import BaseModel, ConfigDict, Field

StylePreset = Literal[
    "cinematic_dolly", "orbit_showcase", "parallax_lifestyle", "macro_reveal", "minimal_studio"
]
Tone = Literal["luxury", "playful", "energetic", "calm", "professional", "friendly"]
ShotType = Literal["front", "detail", "lifestyle", "flat"]


class ImageHint(BaseModel):
    """What the Image Analyzer said about one image (only the fields the planner needs)."""

    model_config = ConfigDict(extra="ignore")

    media_id: str = Field(pattern=r"^[A-Za-z0-9_-]{1,64}$")
    shot_type: ShotType = "front"
    usable_as_first_frame: bool = True
    quality_score: float = Field(default=0.5, ge=0, le=1)


class ProductHint(BaseModel):
    model_config = ConfigDict(extra="ignore")

    category: str | None = Field(default=None, max_length=60)
    materials: list[str] = Field(default_factory=list, max_length=8)
    colors: list[str] = Field(default_factory=list, max_length=6)


class DescribeRequest(BaseModel):
    model_config = ConfigDict(extra="forbid")

    title: str | None = Field(default=None, max_length=160)
    description: str = Field(min_length=1, max_length=2000)
    language: str = Field(default="en", pattern=r"^[a-z]{2}(-[A-Z]{2})?$")
    tone: Tone | None = None
    style_preset: StylePreset | None = None
    product: ProductHint | None = None
    images: list[ImageHint] = Field(default_factory=list, max_length=10)
    negative_prompt_terms: list[str] = Field(default_factory=list, max_length=20)
    use_llm: bool = True


class Benefit(BaseModel):
    rank: int
    text: str
    evidence: str = Field(description="The sentence of the description it came from.")


class ProductSummary(BaseModel):
    name: str | None
    category: str | None
    materials: list[str]
    colors: list[str]
    audience: str | None = None


class Scene(BaseModel):
    index: int
    start_seconds: float
    duration_seconds: float
    source_media_id: str | None
    camera_move: str
    lighting: str
    description: str
    overlay_text: str | None = Field(description="Short on-screen text, or null.")


class Timeline(BaseModel):
    strategy: Literal["two_images", "one_image", "description_only"]
    total_duration_seconds: float
    crossfade_seconds: float
    scene_count: int


class VideoPrompt(BaseModel):
    scene_index: int
    media_id: str | None
    prompt: str
    negative_prompt: str
    duration_seconds: float = Field(description="Length used in the final video.")
    request_seconds: float = Field(description="Length to ask the provider for (a little extra).")


class DescribeResponse(BaseModel):
    source: Literal["rules", "llm"]
    summary: str
    product: ProductSummary
    benefits: list[Benefit]
    features: list[str]
    keywords: list[str]
    tone: Tone
    call_to_action: str
    claims_to_review: list[str] = Field(
        description="Statements that need a human check before publishing (superlatives, health)."
    )
    style_preset: StylePreset
    timeline: Timeline
    scenes: list[Scene]
    prompts: list[VideoPrompt]
    warnings: list[str] = Field(default_factory=list)
