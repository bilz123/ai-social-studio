from __future__ import annotations

import io
import random

from PIL import Image, ImageDraw, ImageFilter


def product_on_white(size: tuple[int, int] = (1200, 1200), colour: str = "#2a4d9b") -> bytes:
    """A crisp rectangle 'product' centred on a white background."""
    image = Image.new("RGB", size, "white")
    draw = ImageDraw.Draw(image)
    w, h = size
    draw.rectangle((w * 0.3, h * 0.25, w * 0.7, h * 0.75), fill=colour)
    draw.rectangle((w * 0.35, h * 0.3, w * 0.65, h * 0.4), fill="#e8c14a")
    return encode(image)


def noisy_scene(size: tuple[int, int] = (1200, 900)) -> bytes:
    """Coloured blocks of varying size: a busy, edge-rich 'lifestyle' scene."""
    rng = random.Random(7)  # noqa: S311  (test data, not security)
    image = Image.new("RGB", size)
    draw = ImageDraw.Draw(image)
    for x in range(0, size[0], 40):
        for y in range(0, size[1], 40):
            colour = (rng.randrange(40, 220), rng.randrange(40, 200), rng.randrange(20, 160))
            draw.rectangle((x, y, x + 39, y + 39), fill=colour)
    return encode(image)


def blurred(size: tuple[int, int] = (1200, 1200)) -> bytes:
    image = Image.open(io.BytesIO(product_on_white(size)))
    return encode(image.filter(ImageFilter.GaussianBlur(40)))


def encode(image: Image.Image, fmt: str = "JPEG", **kwargs: object) -> bytes:
    buffer = io.BytesIO()
    image.save(buffer, format=fmt, **kwargs)
    return buffer.getvalue()
