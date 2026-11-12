#!/usr/bin/env python3
"""Writes each AI service's OpenAPI document to contracts/ai/<service>.openapi.json.

Run from the repository root with every service installed (see `make lint-ai` for the list):
    python scripts/export-ai-openapi.py
Pass --check to fail when the committed files are out of date (used to catch contract drift).
"""

from __future__ import annotations

import importlib
import json
import os
import sys
from pathlib import Path

os.environ.setdefault("AI_ENVIRONMENT", "testing")

SERVICES = {
    "image-analyzer": "image_analyzer",
    "description-analyzer": "description_analyzer",
    "video-generator": "video_generator",
    "video-renderer": "video_renderer",
    "video-optimizer": "video_optimizer",
}


def main() -> int:
    check = "--check" in sys.argv
    out = Path(__file__).resolve().parent.parent / "contracts" / "ai"
    out.mkdir(parents=True, exist_ok=True)
    stale: list[str] = []
    for name, package in SERVICES.items():
        app = importlib.import_module(f"{package}.main").create_app()
        text = json.dumps(app.openapi(), indent=2, sort_keys=True) + "\n"
        target = out / f"{name}.openapi.json"
        if check:
            if not target.exists() or target.read_text() != text:
                stale.append(name)
        else:
            target.write_text(text)
            print(f"wrote {target}")
    if stale:
        print("Out of date: " + ", ".join(stale) + " - run scripts/export-ai-openapi.py")
        return 1
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
