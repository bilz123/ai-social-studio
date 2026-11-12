from __future__ import annotations

import pytest

from description_analyzer import text
from description_analyzer.planner import (
    CROSSFADE_SECONDS,
    PRESETS,
    SHOT_SECONDS,
    TOTAL_SECONDS,
    build_prompts,
    build_scenes,
    choose_preset,
    pick_sources,
)
from description_analyzer.schemas import ImageHint
from description_analyzer.vocab import guess_category

DESCRIPTION = (
    "Our leather backpack keeps your laptop safe all day. "
    "Made from full-grain leather with two zip pockets. "
    "The padded straps make it comfortable on long commutes. "
    "Voted the best bag of 2025. Available in tan and black. "
    "Visit https://example.com/buy for details."
)


def test_planner_math_adds_up_to_exactly_ten_seconds() -> None:
    assert SHOT_SECONDS == 5.5
    assert SHOT_SECONDS + SHOT_SECONDS - CROSSFADE_SECONDS == TOTAL_SECONDS


def hints(*specs: tuple[str, str, bool, float]) -> list[ImageHint]:
    return [
        ImageHint(media_id=m, shot_type=t, usable_as_first_frame=u, quality_score=q)
        for m, t, u, q in specs
    ]


def test_two_usable_images_use_strategy_a_and_prefer_variety() -> None:
    timeline, sources = pick_sources(
        hints(("a", "front", True, 0.9), ("b", "front", True, 0.8), ("c", "lifestyle", True, 0.6))
    )
    assert timeline.strategy == "two_images"
    assert sources == ["a", "c"]


def test_unusable_images_are_ignored() -> None:
    timeline, sources = pick_sources(hints(("a", "front", True, 0.9), ("b", "front", False, 0.95)))
    assert timeline.strategy == "one_image"
    assert sources == ["a", "a"]


def test_no_images_plan_from_the_description_alone() -> None:
    timeline, sources = pick_sources([])
    assert timeline.strategy == "description_only"
    assert sources == [None, None]


def test_scene_timing_and_crossfade_offset() -> None:
    scenes = build_scenes(
        product_label="Backpack",
        sources=["a", "b"],
        strategy="two_images",
        preset=PRESETS["cinematic_dolly"],
        hook_text="Backpack",
        cta_text="Shop now",
        benefit_texts=["Keeps laptops safe", "Comfortable straps"],
    )
    assert [s.start_seconds for s in scenes] == [0.0, 4.5]
    assert scenes[1].start_seconds + scenes[1].duration_seconds == TOTAL_SECONDS
    assert scenes[1].overlay_text == "Shop now"


def test_prompts_follow_the_template_and_ask_for_extra_seconds() -> None:
    scenes = build_scenes(
        product_label="Backpack", sources=["a", "a"], strategy="one_image",
        preset=PRESETS["macro_reveal"], hook_text=None, cta_text="Shop now", benefit_texts=[],
    )  # fmt: skip
    prompts = build_prompts(scenes, subject="Tan leather backpack", extra_negative=["blur"])
    assert prompts[0].prompt.startswith("Tan leather backpack. Camera: macro rack-focus reveal.")
    assert "logo unchanged" in prompts[0].prompt
    assert prompts[0].request_seconds == 6.0
    assert "warped logo" in prompts[0].negative_prompt and "blur" in prompts[0].negative_prompt
    assert scenes[1].camera_move == "macro push-in on a detail"


def test_style_preset_is_chosen_from_category_unless_requested() -> None:
    assert choose_preset(None, "watch") == "macro_reveal"
    assert choose_preset("orbit_showcase", "watch") == "orbit_showcase"
    assert choose_preset(None, None) == "cinematic_dolly"


def test_benefits_are_ranked_short_and_traceable() -> None:
    result = text.benefits(DESCRIPTION)
    assert result[0].rank == 1
    joined = " ".join(b.text.lower() for b in result)
    assert "laptop" in joined and "comfortable" in joined
    assert all(len(b.text) <= 80 for b in result)
    assert all(b.evidence for b in result)


def test_urls_are_removed_and_claims_flagged() -> None:
    assert "example.com" not in text.clean(DESCRIPTION)
    flagged = text.claims(DESCRIPTION)
    assert any("best bag" in c for c in flagged)


def test_features_keywords_and_tone() -> None:
    assert any("leather" in f.lower() for f in text.features(DESCRIPTION))
    assert "backpack" in text.keywords(DESCRIPTION)
    assert text.detect_tone("A premium, elegant, timeless watch") == "luxury"
    assert text.detect_tone("A thing") == "friendly"


@pytest.mark.parametrize(
    ("words", "category"),
    [("Trail running sneakers", "shoes"), ("Soy candle", "home"), ("Quantum flux", None)],
)
def test_category_guess(words: str, category: str | None) -> None:
    assert guess_category(words) == category


def test_description_with_only_symbols_still_gets_a_fallback() -> None:
    assert text.benefits("Nice product for you. Buy it now.")  # falls back to opening sentences
