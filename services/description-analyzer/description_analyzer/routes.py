from __future__ import annotations

import logging
import re
from typing import Literal, cast

from fastapi import APIRouter, Request

from description_analyzer import text
from description_analyzer.llm import InsightsProvider, get_insights
from description_analyzer.planner import (
    PRESETS,
    build_prompts,
    build_scenes,
    choose_preset,
    pick_sources,
)
from description_analyzer.schemas import Benefit, DescribeRequest, DescribeResponse, ProductSummary
from description_analyzer.vocab import guess_category, guess_materials

router = APIRouter(prefix="/v1", tags=["description-analyzer"])
logger = logging.getLogger(__name__)


def _evidence_for(benefit: str, sentences: list[str]) -> str:
    words = set(re.findall(r"[a-z]{3,}", benefit.lower()))
    best = max(
        sentences, key=lambda s: len(words & set(re.findall(r"[a-z]{3,}", s.lower()))), default=""
    )
    return best[:300]


@router.post("/describe", response_model=DescribeResponse)
async def describe(body: DescribeRequest, request: Request) -> DescribeResponse:
    provider = cast("InsightsProvider | None", request.app.state.llm)
    description = text.clean(body.description)
    title = text.clean(body.title) if body.title else None
    warnings: list[str] = []

    category = (body.product.category if body.product else None) or guess_category(
        f"{title or ''} {description}"
    )
    materials = list(body.product.materials) if body.product else []
    materials += [m for m in guess_materials(description) if m not in materials]
    colors = list(body.product.colors) if body.product else []

    benefits = text.benefits(description)
    features = text.features(description)
    tone = body.tone or text.detect_tone(description)
    summary = text.shorten(
        text.sentences(description)[0] if text.sentences(description) else description, 140
    )
    audience: str | None = None
    hook: str | None = None
    cta = text.call_to_action(category)
    source: Literal["rules", "llm"] = "rules"

    if body.use_llm and provider is not None:
        found = await get_insights(provider, title, description, body.language)
        if found is None:
            warnings.append("llm_unavailable")
        else:
            source = "llm"
            sentences = text.sentences(description)
            if found.benefits:
                benefits = [
                    Benefit(rank=i, text=b, evidence=_evidence_for(b, sentences))
                    for i, b in enumerate(found.benefits[:5], start=1)
                ]
            features = found.features or features
            summary = found.summary or summary
            audience = found.audience
            tone = body.tone or found.tone or tone
            hook = found.hook
            cta = found.call_to_action or cta
    elif body.use_llm:
        warnings.append("llm_not_configured")

    if category is None:
        warnings.append("product_category_unknown")
    review = text.claims(description)
    if review:
        warnings.append("claims_need_review")

    preset_name = choose_preset(body.style_preset, category)
    timeline, sources = pick_sources(body.images)
    if timeline.strategy == "description_only":
        warnings.append("no_usable_images")
    elif timeline.strategy == "one_image":
        warnings.append("only_one_usable_image")

    label = (title or category or "product")[:60]
    if hook is None and title:
        hook = text.shorten(title, 32)
    hook = hook or (benefits[0].text[:32] if benefits else None)
    scenes = build_scenes(
        product_label=label,
        sources=sources,
        strategy=timeline.strategy,
        preset=PRESETS[preset_name],
        hook_text=hook,
        cta_text=cta,
        benefit_texts=[b.text for b in benefits],
    )
    subject = ", ".join(
        filter(None, [label, " ".join(colors[:2]) or None, " ".join(materials[:2]) or None])
    )
    prompts = build_prompts(scenes, subject=subject, extra_negative=body.negative_prompt_terms)

    logger.info("description_analyzed", extra={"source": source, "strategy": timeline.strategy})
    return DescribeResponse(
        source=source,
        summary=summary,
        product=ProductSummary(
            name=title, category=category, materials=materials, colors=colors, audience=audience
        ),
        benefits=benefits,
        features=features,
        keywords=text.keywords(description),
        tone=tone,
        call_to_action=cta,
        claims_to_review=review,
        style_preset=preset_name,
        timeline=timeline,
        scenes=scenes,
        prompts=prompts,
        warnings=warnings,
    )
