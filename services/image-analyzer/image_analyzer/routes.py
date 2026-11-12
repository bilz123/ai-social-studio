from __future__ import annotations

import asyncio
import logging
from typing import cast

from fastapi import APIRouter, Request
from studio_common.errors import ServiceError
from studio_common.storage import Storage

from image_analyzer.analysis import analyze_image, read_original, vision_jpeg
from image_analyzer.schemas import AnalyzeRequest, AnalyzeResponse, ImageAnalysis, Preservation
from image_analyzer.settings import ImageAnalyzerSettings
from image_analyzer.vision import (
    VisionProvider,
    heuristic_product,
    identify_with_vision,
    merge_vision,
)

router = APIRouter(prefix="/v1", tags=["image-analyzer"])
logger = logging.getLogger(__name__)

NEGATIVE_TERMS = [
    "warped logo", "morphing shape", "extra objects", "text artifacts",
    "changed colors", "distorted proportions", "flicker",
]  # fmt: skip


def _preservation(images: list[ImageAnalysis], product_colors: list[str]) -> Preservation:
    usable = [i for i in images if i.usable_as_first_frame]
    hero = max(usable or images, key=lambda i: i.quality.score)
    must = ["exact product shape and proportions", "original label, logo and printed text"]
    if product_colors:
        must.append("product colours: " + ", ".join(product_colors))
    return Preservation(
        hero_media_id=hero.media_id if usable else None,
        must_preserve=must,
        negative_prompt_terms=NEGATIVE_TERMS,
    )


@router.post("/analyze", response_model=AnalyzeResponse)
async def analyze(body: AnalyzeRequest, request: Request) -> AnalyzeResponse:
    settings = cast(ImageAnalyzerSettings, request.app.state.settings)
    storage = cast(Storage, request.app.state.storage)
    vision = cast("VisionProvider | None", request.app.state.vision)

    if len(body.images) > settings.max_images:
        raise ServiceError(422, "too_many_images", f"At most {settings.max_images} images.")
    if len({i.media_id for i in body.images}) != len(body.images):
        raise ServiceError(422, "duplicate_media_id", "Each image needs a unique media_id.")

    analyses: list[ImageAnalysis] = []
    best_jpeg: bytes | None = None
    best_score = -1.0
    for ref in body.images:
        async with storage.local_copy(ref.storage_key) as path:
            data = await asyncio.to_thread(read_original, path, settings.max_image_bytes)
        analysis = await asyncio.to_thread(analyze_image, ref.media_id, data, settings.max_pixels)
        analyses.append(analysis)
        if vision and body.use_vision and analysis.quality.score > best_score:
            best_score = analysis.quality.score
            best_jpeg = await asyncio.to_thread(vision_jpeg, data, settings.max_pixels)

    product = heuristic_product(analyses, body.brief)
    warnings: list[str] = []
    if vision and body.use_vision and best_jpeg is not None:
        found = await identify_with_vision(vision, best_jpeg, body.brief)
        if found is None:
            warnings.append("vision_unavailable")
        else:
            product = merge_vision(product, found)
    elif body.use_vision:
        warnings.append("vision_not_configured")
    if product.category is None:
        warnings.append("product_category_unknown")

    logger.info(
        "images_analyzed",
        extra={"count": len(analyses), "source": product.source, "category": product.category},
    )
    return AnalyzeResponse(
        images=analyses,
        product=product,
        preservation=_preservation(analyses, product.colors),
        warnings=warnings,
    )
