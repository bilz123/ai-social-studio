from __future__ import annotations

import json
import logging

import pytest
from pydantic import ValidationError

from app.core.config import Settings
from app.core.logging import REDACTED, JsonFormatter, redact, request_id_var
from app.main import create_app


def test_local_environment_needs_no_secrets(monkeypatch: pytest.MonkeyPatch) -> None:
    # Must hold even when the surrounding shell/CI exports REDIS_URL (e.g. integration runs).
    monkeypatch.delenv("REDIS_URL", raising=False)
    monkeypatch.delenv("AI_REDIS_URL", raising=False)
    settings = Settings(environment="local")
    assert settings.redis_url is None
    assert settings.docs_enabled is True


@pytest.mark.parametrize("environment", ["staging", "production"])
def test_non_local_environments_require_secrets(environment: str) -> None:
    with pytest.raises(ValidationError) as excinfo:
        Settings(environment=environment)

    message = str(excinfo.value)
    assert "REDIS_URL" in message
    assert "AI_HMAC_SECRET_LARAVEL_TO_AI" in message


def test_secrets_are_masked_in_repr() -> None:
    settings = Settings(
        environment="production",
        redis_url="redis://:topsecret@redis:6379/2",
        hmac_secret_laravel_to_ai="s1",
        hmac_secret_ai_to_laravel="s2",
    )

    assert "topsecret" not in repr(settings)
    assert "topsecret" not in str(settings.model_dump())


def test_docs_are_disabled_in_production() -> None:
    settings = Settings(
        environment="production",
        redis_url="redis://:x@redis:6379/2",
        hmac_secret_laravel_to_ai="s1",
        hmac_secret_ai_to_laravel="s2",
    )
    app = create_app(settings)

    assert app.docs_url is None
    assert app.openapi_url is None


def test_redact_masks_nested_sensitive_keys() -> None:
    cleaned = redact(
        {"user": "a", "Authorization": "Bearer x", "nested": {"api_key": "k", "ok": 1}}
    )

    assert cleaned == {
        "user": "a",
        "Authorization": REDACTED,
        "nested": {"api_key": REDACTED, "ok": 1},
    }


def test_json_formatter_emits_json_with_request_id_and_redaction() -> None:
    formatter = JsonFormatter("ai-api")
    record = logging.LogRecord("t", logging.INFO, __file__, 1, "hello %s", ("world",), None)
    record.access_token = "abc"
    record.workspace_id = 7

    token = request_id_var.set("req-12345678")
    try:
        payload = json.loads(formatter.format(record))
    finally:
        request_id_var.reset(token)

    assert payload["message"] == "hello world"
    assert payload["service"] == "ai-api"
    assert payload["request_id"] == "req-12345678"
    assert payload["workspace_id"] == 7
    assert payload["access_token"] == REDACTED
