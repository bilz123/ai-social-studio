"""Deterministic reading of a product description. No network, no model."""

from __future__ import annotations

import re
from collections import Counter

from description_analyzer.schemas import Benefit, Tone

_SENTENCE = re.compile(r"(?<=[.!?])\s+|\n+|\s*[••*\-]\s+(?=\S)")
_URL = re.compile(r"https?://\S+|www\.\S+", re.I)

_BENEFIT_CUES = (
    "keeps",
    "lasts",
    "long-lasting",
    "durable",
    "lightweight",
    "waterproof",
    "water-resistant",
    "comfortable",
    "easy to",
    "perfect for",
    "ideal for",
    "helps",
    "reduces",
    "ensures",
    "protects",
    "fits",
    "all-day",
    "hours",
    "no more",
    "never",
    "designed to",
    "so you",
    "allows",
    "makes it",
    "built to",
    "soft",
    "smooth",
    "fast",
    "instant",
    "portable",
    "compact",
    "sustainable",
    "handmade",
    "organic",
    "natural",
    "adjustable",
    "rechargeable",
)
_FEATURE_CUES = (
    "made from",
    "made of",
    "crafted",
    "features",
    "includes",
    "comes with",
    "available in",
    "material",
    "size",
    "capacity",
    "battery",
    "inch",
    "cm",
    "mm",
    "ml",
    "oz",
    "kg",
    "pockets",
    "compartment",
    "zip",
    "strap",
)
_CLAIM_PATTERNS = (
    r"\bbest\b",
    r"#\s?1\b",
    r"\bnumber one\b",
    r"\bguarantee[d]?\b",
    r"\bcure[sd]?\b",
    r"\bclinically\b",
    r"\bproven\b",
    r"\b100\s?%",
    r"\bFDA\b",
    r"\bdoctor\b",
    r"\bheals?\b",
    r"\bno\.? ?1\b",
    r"\bunbeatable\b",
    r"\bfree shipping\b",
    r"\bweight loss\b",
    r"\banti-?aging\b",
)
_STOP = frozenset(
    [
        "a",
        "an",
        "the",
        "and",
        "or",
        "but",
        "of",
        "for",
        "to",
        "in",
        "on",
        "at",
        "by",
        "with",
        "from",
        "as",
        "is",
        "are",
        "was",
        "were",
        "be",
        "been",
        "it",
        "its",
        "this",
        "that",
        "these",
        "those",
        "your",
        "you",
        "our",
        "we",
        "they",
        "their",
        "his",
        "her",
        "he",
        "she",
        "them",
        "us",
        "i",
        "not",
        "no",
        "yes",
        "can",
        "will",
        "just",
        "also",
        "very",
        "more",
        "most",
        "than",
        "then",
        "so",
        "if",
        "into",
        "out",
        "up",
        "over",
        "any",
        "all",
        "each",
        "other",
        "such",
        "own",
        "same",
        "has",
        "have",
        "had",
        "do",
        "does",
        "did",
        "get",
        "gets",
        "got",
        "new",
        "one",
        "two",
    ]
)

_TONE_WORDS: dict[Tone, tuple[str, ...]] = {
    "luxury": ("luxury", "premium", "elegant", "exclusive", "refined", "timeless", "handcrafted"),
    "playful": ("fun", "playful", "colorful", "colourful", "cute", "quirky", "joy"),
    "energetic": ("power", "performance", "sport", "active", "fast", "boost", "energy", "workout"),
    "calm": ("calm", "gentle", "soothing", "relax", "peaceful", "soft", "natural"),
    "professional": ("professional", "office", "business", "precision", "reliable", "enterprise"),
    "friendly": ("everyday", "easy", "family", "friendly", "simple", "love"),
}


def clean(text: str) -> str:
    """Strip URLs and control characters; collapse whitespace."""
    text = _URL.sub("", text)
    text = "".join(c for c in text if c.isprintable() or c in "\n\t")
    return re.sub(r"[ \t]+", " ", text).strip()


def sentences(text: str) -> list[str]:
    parts = [p.strip(" \t-•*") for p in _SENTENCE.split(clean(text)) if p and p.strip(" \t-•*")]
    return [p for p in parts if len(p) >= 8][:40]


def shorten(sentence: str, limit: int = 80) -> str:
    sentence = re.sub(r"\s+", " ", sentence).strip().rstrip(".!")
    if len(sentence) <= limit:
        return sentence[:1].upper() + sentence[1:]
    cut = sentence[:limit].rsplit(" ", 1)[0]
    return cut[:1].upper() + cut[1:]


def benefits(text: str, limit: int = 5) -> list[Benefit]:
    scored: list[tuple[float, int, str]] = []
    for order, sentence in enumerate(sentences(text)):
        lowered = sentence.lower()
        score = sum(1.0 for cue in _BENEFIT_CUES if cue in lowered)
        score += 0.5 if re.search(r"\d", sentence) and score else 0
        score -= 0.5 * sum(1 for cue in _FEATURE_CUES if cue in lowered) if score < 1 else 0
        if score > 0:
            scored.append((score, -order, sentence))
    scored.sort(reverse=True)
    chosen = scored[:limit]
    if not chosen:  # nothing looked like a benefit: fall back to the opening sentences
        chosen = [(0.1, -i, s) for i, s in enumerate(sentences(text)[:2])]
    return [
        Benefit(rank=rank, text=shorten(sentence), evidence=sentence[:300])
        for rank, (_, _, sentence) in enumerate(chosen, start=1)
    ]


def features(text: str, limit: int = 6) -> list[str]:
    out: list[str] = []
    for sentence in sentences(text):
        lowered = sentence.lower()
        if any(cue in lowered for cue in _FEATURE_CUES):
            out.append(shorten(sentence, 70))
    return out[:limit]


def keywords(text: str, limit: int = 8) -> list[str]:
    words = re.findall(r"[a-zA-Z][a-zA-Z'-]{2,}", clean(text).lower())
    counts = Counter(w for w in words if w not in _STOP)
    return [w for w, _ in counts.most_common(limit)]


def claims(text: str) -> list[str]:
    found: list[str] = []
    for sentence in sentences(text):
        if any(re.search(p, sentence, re.I) for p in _CLAIM_PATTERNS):
            found.append(sentence[:160])
    return found[:5]


def detect_tone(text: str) -> Tone:
    lowered = text.lower()
    scores = {tone: sum(lowered.count(w) for w in words) for tone, words in _TONE_WORDS.items()}
    best = max(scores, key=lambda t: scores[t])
    return best if scores[best] > 0 else "friendly"


def call_to_action(category: str | None) -> str:
    return {
        "food_drink": "Taste it today",
        "beauty": "Try it today",
        "apparel": "Find your fit",
        "shoes": "Find your pair",
    }.get(category or "", "Shop now")
