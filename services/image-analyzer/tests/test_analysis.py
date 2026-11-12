from __future__ import annotations

import io

import pytest
from PIL import Image
from studio_common.errors import ServiceError

from image_analyzer.analysis import analyze_image
from tests.helpers import blurred, encode, noisy_scene, product_on_white

MAX = 36_000_000


def test_clean_product_shot_is_usable_and_described() -> None:
    result = analyze_image("m1", product_on_white(), MAX)
    assert (result.width, result.height, result.format) == (1200, 1200, "jpeg")
    assert result.background.kind == "white"
    assert result.subject.centered
    assert 0.15 < result.subject.coverage < 0.5
    assert result.usable_as_first_frame
    assert result.risks == []
    assert result.colors[0].name == "white"
    assert {c.name for c in result.colors} & {"blue", "navy"}
    assert len(result.sha256) == 64


def test_blurry_image_is_flagged_and_not_usable() -> None:
    result = analyze_image("m", blurred(), MAX)
    assert "blurry" in result.risks
    assert not result.usable_as_first_frame


def test_small_image_is_flagged_low_resolution() -> None:
    result = analyze_image("m", product_on_white((400, 400)), MAX)
    assert "low_resolution" in result.risks
    assert not result.usable_as_first_frame


def test_busy_scene_is_a_lifestyle_shot() -> None:
    result = analyze_image("m", noisy_scene(), MAX)
    assert result.background.kind == "complex"
    assert result.shot_type == "lifestyle"


def test_wide_product_cut_by_a_vertical_crop_is_reported() -> None:
    image = Image.new("RGB", (2400, 1200), "white")
    for x in range(200, 2200):
        for y in range(450, 750):
            image.putpixel((x, y), (30, 60, 150))
    result = analyze_image("m", encode(image), MAX)
    assert "subject_lost_in_9_16_crop" in result.risks
    assert not result.usable_as_first_frame


def test_exif_orientation_is_applied_to_dimensions_only() -> None:
    image = Image.new("RGB", (1000, 600), "white")
    exif = Image.Exif()
    exif[0x0112] = 6  # rotate 90 degrees clockwise when displayed
    data = encode(image, exif=exif)
    result = analyze_image("m", data, MAX)
    assert (result.width, result.height) == (600, 1000)


def test_png_with_transparency_is_flagged() -> None:
    image = Image.new("RGBA", (800, 800), (0, 0, 0, 0))
    image.paste((200, 30, 40, 255), (200, 200, 600, 600))
    result = analyze_image("m", encode(image, "PNG"), MAX)
    assert "has_transparency" in result.risks


def test_analysis_never_changes_the_input_bytes() -> None:
    data = product_on_white()
    before = bytes(data)
    analyze_image("m", data, MAX)
    assert data == before


@pytest.mark.parametrize(
    ("data", "code"),
    [
        (b"not an image", "image_corrupt"),
        (product_on_white()[:2000], "image_corrupt"),
        (encode(Image.new("RGB", (64, 64)), "GIF"), "image_unsupported_type"),
        (encode(Image.new("RGB", (4000, 4000))), "image_dimensions_too_large"),
    ],
)
def test_bad_files_are_rejected(data: bytes, code: str) -> None:
    limit = 10_000_000 if code == "image_dimensions_too_large" else MAX
    with pytest.raises(ServiceError) as caught:
        analyze_image("m", data, limit)
    assert caught.value.code == code


def test_animated_images_are_flagged() -> None:
    frames = [Image.new("RGB", (800, 800), c) for c in ("red", "blue")]
    buffer = io.BytesIO()
    frames[0].save(buffer, format="WEBP", save_all=True, append_images=frames[1:], duration=100)
    result = analyze_image("m", buffer.getvalue(), MAX)
    assert "animated" in result.risks
