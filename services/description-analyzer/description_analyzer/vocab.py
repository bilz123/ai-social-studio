"""Product vocabulary used to guess a category and materials from free text."""

from __future__ import annotations

import re

CATEGORIES: dict[str, tuple[str, ...]] = {
    "bag": ("bag", "backpack", "handbag", "tote", "purse", "wallet"),
    "shoes": ("shoe", "sneaker", "boot", "sandal", "trainer"),
    "apparel": ("shirt", "t-shirt", "hoodie", "jacket", "dress", "jeans", "coat", "sweater"),
    "watch": ("watch", "wristwatch"),
    "jewelry": ("ring", "necklace", "bracelet", "earring", "jewelry", "jewellery"),
    "beauty": ("cream", "serum", "lipstick", "perfume", "skincare", "makeup", "shampoo"),
    "food_drink": ("coffee", "tea", "chocolate", "snack", "sauce", "drink", "beer", "wine"),
    "electronics": ("phone", "headphone", "earbuds", "speaker", "laptop", "camera", "charger"),
    "home": ("candle", "mug", "lamp", "pillow", "vase", "furniture", "chair", "table"),
    "fitness": ("yoga", "dumbbell", "bottle", "mat", "gym"),
}

MATERIALS = (
    "leather",
    "cotton",
    "wool",
    "silk",
    "linen",
    "denim",
    "steel",
    "stainless",
    "gold",
    "silver",
    "wood",
    "bamboo",
    "glass",
    "ceramic",
    "plastic",
    "rubber",
    "canvas",
    "nylon",
    "aluminium",
    "aluminum",
    "paper",
)


def guess_category(text: str) -> str | None:
    lowered = text.lower()
    for category, words in CATEGORIES.items():
        if any(re.search(rf"\b{re.escape(w)}s?\b", lowered) for w in words):
            return category
    return None


def guess_materials(text: str) -> list[str]:
    lowered = text.lower()
    return [m for m in MATERIALS if re.search(rf"\b{m}\b", lowered)]
