from __future__ import annotations

from pathlib import Path
from typing import Any

import pytest
from pydantic import ValidationError

from video_renderer.graph import (
    ResolvedInputs,
    build_plan,
    chain_segments,
    output_size,
    overlay_filter,
    segment_chain,
    wrap_text,
)
from video_renderer.schemas import RenderRequest, Segment, TextOverlay

FONT = "/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf"


def request(**over: Any) -> RenderRequest:
    base: dict[str, Any] = {
        "output_key": "out/final.mp4",
        "duration_seconds": 10.0,
        "segments": [
            {"kind": "clip", "key": "a.mp4", "duration_seconds": 5.5},
            {"kind": "clip", "key": "b.mp4", "duration_seconds": 5.5},
        ],
    }
    return RenderRequest.model_validate({**base, **over})


def test_ten_second_two_clip_timeline_is_valid_and_crossfade_offset_is_4_5() -> None:
    filters, label = chain_segments(request())
    assert label == "x1"
    assert "offset=4.5" in filters[0] and "duration=1" in filters[0]


def test_three_segment_offsets_accumulate() -> None:
    req = request(
        duration_seconds=10.0,
        segments=[
            {"kind": "image", "key": f"{c}.jpg", "duration_seconds": d}
            for c, d in (("a", 4.0), ("b", 4.0), ("c", 4.0))
        ],
        transition={"type": "wipeleft", "duration_seconds": 1.0},
    )
    filters, label = chain_segments(req)
    assert label == "x2"
    assert "offset=3[x1]" in filters[0]  # 4 - 1
    assert "offset=6[x2]" in filters[1]  # (4 + 4 - 1) - 1
    assert "xfade=transition=wipeleft" in filters[1]


@pytest.mark.parametrize(
    "bad",
    [
        {"duration_seconds": 9.0},  # does not add up
        {"output_key": "out/final.mov"},
        {"segments": []},
        {"transition": {"type": "exec(rm -rf)", "duration_seconds": 1}},
        {"transition": {"type": "fade", "duration_seconds": 6}},
        {"overlays": [{"text": "x", "start_seconds": 1, "end_seconds": 20}]},
        {"overlays": [{"text": "x", "start_seconds": 3, "end_seconds": 2}]},
        {"overlays": [{"text": "x", "start_seconds": 0, "end_seconds": 2, "color": "red;rm"}]},
        {"overlays": [{"text": "مرحبا", "start_seconds": 0, "end_seconds": 2}]},
        {"overlays": [{"text": "नमस्ते", "start_seconds": 0, "end_seconds": 2}]},
        {"fps": 29},
        {"unknown": True},
        {"logo": {"key": "../logo.png"}},
    ],
)
def test_invalid_requests_are_rejected(bad: dict[str, Any]) -> None:
    with pytest.raises(ValidationError):
        request(**bad)


def test_text_goes_through_a_file_not_the_filter_string(tmp_path: Path) -> None:
    nasty = "50% off: it's 'great' \\ ; [x]\"quote\" %{localtime}"
    req = request(overlays=[{"text": nasty, "start_seconds": 1, "end_seconds": 4}])
    plan = build_plan(
        req,
        ResolvedInputs([tmp_path / "a.mp4", tmp_path / "b.mp4"]),
        tmp_path / "o.mp4",
        tmp_path,
        FONT,
    )
    graph = plan.args[plan.args.index("-filter_complex") + 1]
    assert "50% off" not in graph and "great" not in graph and "localtime" not in graph
    assert "expansion=none" in graph
    assert (tmp_path / "overlay0.txt").read_text() == "\n".join(
        wrap_text(nasty, 64, 1080).split("\n")
    )


def test_every_input_is_opened_with_a_local_file_whitelist(tmp_path: Path) -> None:
    req = request(logo={"key": "l.png"}, music={"key": "m.mp3"})
    inputs = ResolvedInputs(
        [tmp_path / "a.mp4", tmp_path / "b.mp4"], tmp_path / "l.png", tmp_path / "m.mp3"
    )
    plan = build_plan(req, inputs, tmp_path / "o.mp4", tmp_path, FONT)
    assert plan.args.count("-protocol_whitelist") == plan.args.count("-i") == 4
    assert plan.args[plan.args.index("-stream_loop") + 1] == "-1"


def test_output_spec_matches_the_master_requirements(tmp_path: Path) -> None:
    plan = build_plan(
        request(),
        ResolvedInputs([tmp_path / "a", tmp_path / "b"]),
        tmp_path / "o.mp4",
        tmp_path,
        FONT,
    )
    a = plan.args
    assert (plan.width, plan.height) == (1080, 1920)
    for flag, value in [
        ("-c:v", "libx264"),
        ("-profile:v", "high"),
        ("-pix_fmt", "yuv420p"),
        ("-r", "30"),
        ("-c:a", "aac"),
        ("-ar", "48000"),
        ("-ac", "2"),
        ("-movflags", "+faststart"),
        ("-t", "10"),
    ]:
        assert a[a.index(flag) + 1] == value


def test_preview_resolution_is_half_size() -> None:
    assert output_size(request(resolution="preview")) == (540, 960)
    assert output_size(request(aspect_ratio="16:9")) == (1920, 1080)


def test_text_stays_inside_the_platform_safe_zones() -> None:
    overlay = TextOverlay(text="Hi", start_seconds=0, end_seconds=2, position="bottom")
    bottom = overlay_filter(overlay, Path("x.txt"), FONT, 1080, 1920)
    assert "y=h-h*0.15-text_h" in bottom
    top = overlay_filter(
        overlay.model_copy(update={"position": "top"}), Path("x.txt"), FONT, 1080, 1920
    )
    assert "y=h*0.09" in top


def test_zoom_expressions_for_stills_and_clips() -> None:
    still = Segment.model_validate(
        {
            "kind": "image",
            "key": "a.jpg",
            "duration_seconds": 5.5,
            "zoom": {"style": "in", "amount": 0.2, "focus_x": 0.3},
        }
    )
    chain = segment_chain(still, 0, 1080, 1920, 30)
    assert "z='1+0.2*on/165'" in chain and "d=165" in chain and "*0.3" in chain
    out = segment_chain(
        still.model_copy(update={"zoom": still.zoom.model_copy(update={"style": "out"})}),
        0,
        1080,
        1920,
        30,
    )
    assert "z='1+0.2*(1-on/165)'" in out
    clip = Segment.model_validate({"kind": "clip", "key": "a.mp4", "duration_seconds": 5.5})
    assert "eval=frame" in segment_chain(clip, 0, 1080, 1920, 30)
    none = Segment.model_validate(
        {"kind": "clip", "key": "a.mp4", "duration_seconds": 5.5, "zoom": {"style": "none"}}
    )
    assert "eval=frame" not in segment_chain(none, 0, 1080, 1920, 30)


def test_long_text_is_wrapped_to_three_lines() -> None:
    wrapped = wrap_text("word " * 40, 64, 1080)
    assert len(wrapped.split("\n")) <= 3
