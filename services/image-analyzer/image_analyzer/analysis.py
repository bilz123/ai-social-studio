"""Pure image measurements. Nothing here writes to the image or to disk."""

from __future__ import annotations

import hashlib
import io
from pathlib import Path

import numpy as np
from PIL import Image, ImageOps, UnidentifiedImageError
from studio_common.errors import ServiceError

from image_analyzer.schemas import (
    Background,
    Box,
    ImageAnalysis,
    NamedColor,
    Quality,
    Risk,
    ShotType,
    Subject,
)

WORK_SIZE = 256
FORMATS = {"JPEG": "jpeg", "PNG": "png", "WEBP": "webp"}

# A small named palette so colours can be described in words ("tan", "navy").
_PALETTE: dict[str, tuple[int, int, int]] = {
    "black": (20, 20, 20), "white": (245, 245, 245), "gray": (128, 128, 128),
    "silver": (192, 192, 192), "red": (200, 30, 40), "orange": (240, 130, 30),
    "yellow": (240, 210, 50), "green": (50, 160, 70), "teal": (30, 140, 140),
    "blue": (40, 90, 200), "navy": (20, 30, 90), "purple": (120, 60, 160),
    "pink": (240, 130, 170), "brown": (110, 70, 40), "tan": (200, 165, 120),
    "beige": (225, 210, 180), "gold": (200, 160, 50),
}  # fmt: skip


def color_name(rgb: tuple[int, int, int]) -> str:
    return min(
        _PALETTE, key=lambda n: sum((a - b) ** 2 for a, b in zip(_PALETTE[n], rgb, strict=True))
    )


def _hex(rgb: tuple[int, int, int]) -> str:
    return "#{:02x}{:02x}{:02x}".format(*rgb)


def load(data: bytes, max_pixels: int) -> tuple[Image.Image, str, bool, int]:
    """Decode `data` into a working copy. Returns (image, format, has_alpha, frames)."""
    try:
        with Image.open(io.BytesIO(data)) as probe:
            fmt = FORMATS.get(probe.format or "")
            if fmt is None:
                raise ServiceError(422, "image_unsupported_type", "Only JPEG, PNG and WebP images.")
            if probe.width * probe.height > max_pixels:
                raise ServiceError(422, "image_dimensions_too_large", "The image is too large.")
            frames = getattr(probe, "n_frames", 1)
            has_alpha = probe.mode in ("RGBA", "LA") or "transparency" in probe.info
            probe.load()  # full decode: truncated files fail here
            image = ImageOps.exif_transpose(probe)  # a copy; the original bytes stay untouched
            return image.convert("RGBA" if has_alpha else "RGB"), fmt, has_alpha, frames
    except ServiceError:
        raise
    except (
        UnidentifiedImageError,
        OSError,
        SyntaxError,
        ValueError,
        Image.DecompressionBombError,
    ) as error:
        raise ServiceError(
            422, "image_corrupt", "The file could not be read as an image."
        ) from error


def read_original(path: Path, max_bytes: int) -> bytes:
    size = path.stat().st_size
    if size == 0:
        raise ServiceError(422, "image_empty", "The image file is empty.")
    if size > max_bytes:
        raise ServiceError(422, "image_too_large", "The image file is too large.")
    return path.read_bytes()


def _flatten(image: Image.Image) -> Image.Image:
    if image.mode == "RGBA":
        base = Image.new("RGB", image.size, (255, 255, 255))
        base.paste(image, mask=image.getchannel("A"))
        return base
    return image


def _small(image: Image.Image) -> np.ndarray:
    work = _flatten(image).copy()
    work.thumbnail((WORK_SIZE, WORK_SIZE))
    return np.asarray(work, dtype=np.float32)


def _laplacian_variance(gray: np.ndarray) -> float:
    lap = (
        -4 * gray[1:-1, 1:-1] + gray[:-2, 1:-1] + gray[2:, 1:-1] + gray[1:-1, :-2] + gray[1:-1, 2:]
    )
    return float(lap.var())


def _background(rgb: np.ndarray) -> tuple[Background, np.ndarray | None]:
    h, w, _ = rgb.shape
    t = max(2, min(h, w) // 20)
    border = np.concatenate(
        [rgb[:t].reshape(-1, 3), rgb[-t:].reshape(-1, 3),
         rgb[:, :t].reshape(-1, 3), rgb[:, -t:].reshape(-1, 3)]
    )  # fmt: skip
    median = np.median(border, axis=0)
    spread = float(np.mean(np.abs(border - median)))
    if spread > 14:
        return Background(kind="complex"), None
    colour = (int(median[0]), int(median[1]), int(median[2]))
    kind = "white" if min(colour) >= 235 else "solid"
    return Background(kind=kind, hex=_hex(colour)), median


def _subject(rgb: np.ndarray, bg_colour: np.ndarray | None) -> tuple[Subject, np.ndarray]:
    gray = rgb.mean(axis=2)
    if bg_colour is not None:
        mask = np.abs(rgb - bg_colour).sum(axis=2) > 45
    else:
        gy, gx = np.gradient(gray)
        magnitude = np.hypot(gx, gy)
        mask = magnitude > np.percentile(magnitude, 75)
    h, w = mask.shape
    ys, xs = np.where(mask)
    if len(xs) < 20:
        box = Box(x=0.0, y=0.0, w=1.0, h=1.0)
    else:
        x0, x1 = np.percentile(xs, [1, 99])
        y0, y1 = np.percentile(ys, [1, 99])
        box = Box(
            x=round(float(x0) / w, 4),
            y=round(float(y0) / h, 4),
            w=round(float(x1 - x0 + 1) / w, 4),
            h=round(float(y1 - y0 + 1) / h, 4),
        )
    cx, cy = box.x + box.w / 2, box.y + box.h / 2
    centered = abs(cx - 0.5) < 0.15 and abs(cy - 0.5) < 0.15
    return Subject(box=box, coverage=round(min(1.0, box.w * box.h), 4), centered=centered), mask


def _colors(image: Image.Image, count: int = 5) -> list[NamedColor]:
    small = _flatten(image).copy()
    small.thumbnail((96, 96))
    quant = small.quantize(colors=count, method=Image.Quantize.MEDIANCUT)
    palette = quant.getpalette() or []
    total = small.width * small.height
    counts = quant.histogram()[:count]
    out: list[NamedColor] = []
    for index in sorted(range(len(counts)), key=lambda i: -counts[i]):
        if counts[index] == 0:
            continue
        rgb = (palette[index * 3], palette[index * 3 + 1], palette[index * 3 + 2])
        out.append(
            NamedColor(hex=_hex(rgb), name=color_name(rgb), share=round(counts[index] / total, 3))
        )
    return out


def _crop_keeps_subject(width: int, height: int, box: Box) -> bool:
    """Would a centred 9:16 crop (what the renderer does) keep the whole subject?"""
    target = 9 / 16
    ratio = width / height
    if ratio <= target:
        return True  # already as narrow as 9:16 (or narrower): nothing is cut sideways
    keep = target / ratio  # fraction of the width that survives
    left = (1 - keep) / 2
    return box.x >= left - 0.01 and box.x + box.w <= left + keep + 0.01


def analyze_image(media_id: str, data: bytes, max_pixels: int) -> ImageAnalysis:
    image, fmt, has_alpha, frames = load(data, max_pixels)
    width, height = image.size
    rgb = _small(image)
    gray = rgb.mean(axis=2)

    sharpness = _laplacian_variance(gray)
    brightness = float(gray.mean() / 255)
    contrast = float(min(1.0, gray.std() / 80))
    background, bg_colour = _background(rgb)
    subject, _ = _subject(rgb, bg_colour)

    resolution = min(1.0, min(width, height) / 1080)
    focus = min(1.0, sharpness / 120)
    exposure = 1.0 - min(1.0, abs(brightness - 0.5) * 1.6)
    score = round(0.4 * resolution + 0.35 * focus + 0.15 * exposure + 0.10 * contrast, 3)

    risks: list[Risk] = []
    if min(width, height) < 720:
        risks.append("low_resolution")
    if sharpness < 15:
        risks.append("blurry")
    if brightness < 0.15:
        risks.append("too_dark")
    if brightness > 0.92 and background.kind != "white":
        risks.append("overexposed")
    box = subject.box
    if min(box.x, box.y) < 0.01 or box.x + box.w > 0.99 or box.y + box.h > 0.99:
        risks.append("subject_touches_edge")
    if not _crop_keeps_subject(width, height, box):
        risks.append("subject_lost_in_9_16_crop")
    if has_alpha:
        risks.append("has_transparency")
    if frames > 1:
        risks.append("animated")

    shot: ShotType
    if background.kind == "complex":
        shot = "lifestyle"
    elif subject.coverage > 0.75:
        shot = "detail"
    elif subject.coverage < 0.25:
        shot = "flat"
    else:
        shot = "front"

    blockers = {"low_resolution", "blurry", "too_dark", "animated", "subject_lost_in_9_16_crop"}
    return ImageAnalysis(
        media_id=media_id,
        format=fmt,
        width=width,
        height=height,
        aspect_ratio=round(width / height, 4),
        sha256=hashlib.sha256(data).hexdigest(),
        quality=Quality(
            score=score,
            sharpness=round(sharpness, 2),
            brightness=round(brightness, 3),
            contrast=round(contrast, 3),
        ),
        colors=_colors(image),
        background=background,
        subject=subject,
        shot_type=shot,
        usable_as_first_frame=score >= 0.45 and not blockers.intersection(risks),
        risks=risks,
    )


def vision_jpeg(data: bytes, max_pixels: int, side: int = 768) -> bytes:
    """A downscaled JPEG copy for a vision model. The original is never altered."""
    image, _, _, _ = load(data, max_pixels)
    work = _flatten(image).copy()
    work.thumbnail((side, side))
    buffer = io.BytesIO()
    work.save(buffer, format="JPEG", quality=85)
    return buffer.getvalue()
