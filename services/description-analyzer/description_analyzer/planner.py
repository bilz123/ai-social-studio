"""Turns an analysed description plus image hints into a 10-second scene plan and prompts.

The structure (how many shots, how long, where the crossfade sits) is always computed here, never
by a model, so the result always adds up to exactly 10 seconds.
"""

from __future__ import annotations

from dataclasses import dataclass
from typing import Literal

from description_analyzer.schemas import (
    ImageHint,
    Scene,
    StylePreset,
    Timeline,
    VideoPrompt,
)

TOTAL_SECONDS = 10.0
CROSSFADE_SECONDS = 1.0
SHOT_SECONDS = (TOTAL_SECONDS + CROSSFADE_SECONDS) / 2  # 5.5 + 5.5 - 1.0 = 10.0
REQUEST_PADDING_SECONDS = 0.5  # ask the provider for a little more than we use

DEFAULT_NEGATIVE = (
    "warped logo, morphing shape, extra objects, text artifacts, changed colors, "
    "distorted proportions, flicker, watermark"
)


@dataclass(frozen=True)
class Preset:
    first_camera: str
    second_camera: str
    lighting: str
    music_mood: str


PRESETS: dict[StylePreset, Preset] = {
    "cinematic_dolly": Preset(
        "slow dolly-in", "slow parallax push", "soft key light, deep shadows", "cinematic"
    ),
    "orbit_showcase": Preset(
        "slow 25 degree orbit", "slow dolly-in", "glossy studio highlights", "upbeat"
    ),
    "parallax_lifestyle": Preset(
        "gentle parallax push", "slow pan right", "warm natural daylight", "warm"
    ),
    "macro_reveal": Preset(
        "macro rack-focus reveal", "slow dolly-in", "crisp directional light", "minimal"
    ),
    "minimal_studio": Preset(
        "slow dolly-in", "slow zoom out", "clean even softbox light", "minimal"
    ),
}

_BY_CATEGORY: dict[str, StylePreset] = {
    "watch": "macro_reveal",
    "jewelry": "macro_reveal",
    "beauty": "minimal_studio",
    "electronics": "orbit_showcase",
    "shoes": "orbit_showcase",
    "bag": "cinematic_dolly",
    "apparel": "parallax_lifestyle",
    "food_drink": "parallax_lifestyle",
    "home": "parallax_lifestyle",
}


def choose_preset(requested: StylePreset | None, category: str | None) -> StylePreset:
    return requested or _BY_CATEGORY.get(category or "", "cinematic_dolly")


def _usable(images: list[ImageHint]) -> list[ImageHint]:
    usable = [i for i in images if i.usable_as_first_frame] or []
    return sorted(usable, key=lambda i: -i.quality_score)


def pick_sources(images: list[ImageHint]) -> tuple[Timeline, list[str | None]]:
    """Strategy A (two images), B (one image, second shot is a macro crop) or description-only."""
    usable = _usable(images)
    if len(usable) >= 2:
        hero = usable[0]
        # Second shot prefers a different kind of picture (detail or lifestyle) for variety.
        others = [i for i in usable[1:] if i.shot_type != hero.shot_type] or usable[1:]
        strategy: Literal["two_images", "one_image", "description_only"] = "two_images"
        sources: list[str | None] = [hero.media_id, others[0].media_id]
    elif len(usable) == 1:
        strategy = "one_image"
        sources = [usable[0].media_id, usable[0].media_id]
    else:
        strategy = "description_only"
        sources = [None, None]
    timeline = Timeline(
        strategy=strategy,
        total_duration_seconds=TOTAL_SECONDS,
        crossfade_seconds=CROSSFADE_SECONDS,
        scene_count=2,
    )
    return timeline, sources


def build_scenes(
    *,
    product_label: str,
    sources: list[str | None],
    strategy: str,
    preset: Preset,
    hook_text: str | None,
    cta_text: str,
    benefit_texts: list[str],
) -> list[Scene]:
    second_camera = "macro push-in on a detail" if strategy == "one_image" else preset.second_camera
    second_benefit = benefit_texts[1] if len(benefit_texts) > 1 else (benefit_texts or [""])[0]
    first_focus = benefit_texts[0] if benefit_texts else f"the {product_label}"
    return [
        Scene(
            index=0,
            start_seconds=0.0,
            duration_seconds=SHOT_SECONDS,
            source_media_id=sources[0],
            camera_move=preset.first_camera,
            lighting=preset.lighting,
            description=f"Hero shot of the {product_label}. Emphasise: {first_focus}.",
            overlay_text=hook_text,
        ),
        Scene(
            index=1,
            start_seconds=SHOT_SECONDS - CROSSFADE_SECONDS,
            duration_seconds=SHOT_SECONDS,
            source_media_id=sources[1],
            camera_move=second_camera,
            lighting=preset.lighting,
            description=(
                f"Closer look at the {product_label}. Emphasise: {second_benefit or 'the details'}."
            ),
            overlay_text=cta_text,
        ),
    ]


def build_prompts(
    scenes: list[Scene],
    *,
    subject: str,
    extra_negative: list[str],
) -> list[VideoPrompt]:
    negative = (
        ", ".join([DEFAULT_NEGATIVE, *extra_negative]) if extra_negative else DEFAULT_NEGATIVE
    )
    prompts: list[VideoPrompt] = []
    for scene in scenes:
        prompt = (
            f"{subject}. Camera: {scene.camera_move}. Lighting: {scene.lighting}. "
            "Cinematic, shallow depth of field, natural motion, product shape and logo "
            "unchanged, no text changes."
        )
        prompts.append(
            VideoPrompt(
                scene_index=scene.index,
                media_id=scene.source_media_id,
                prompt=prompt,
                negative_prompt=negative,
                duration_seconds=scene.duration_seconds,
                request_seconds=scene.duration_seconds + REQUEST_PADDING_SECONDS,
            )
        )
    return prompts
