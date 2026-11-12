"""Environment-driven settings. No credentials live in code or in the repository."""

from __future__ import annotations

from functools import lru_cache
from typing import Literal, Self

from pydantic import AliasChoices, Field, SecretStr, model_validator
from pydantic_settings import BaseSettings, SettingsConfigDict

Environment = Literal["local", "testing", "staging", "production"]


class Settings(BaseSettings):
    model_config = SettingsConfigDict(env_prefix="AI_", extra="ignore", case_sensitive=False)

    service_name: str = "ai-api"
    environment: Environment = "local"
    log_level: str = "INFO"
    readiness_timeout_seconds: float = 2.5

    # e.g. redis://:password@redis:6379/2 (a dedicated logical DB for the AI queue).
    redis_url: SecretStr | None = Field(
        default=None, validation_alias=AliasChoices("REDIS_URL", "AI_REDIS_URL")
    )

    # Service-to-service HMAC secrets (separate secret per direction). Used from Phase 2.
    hmac_secret_laravel_to_ai: SecretStr | None = None
    hmac_secret_ai_to_laravel: SecretStr | None = None

    @property
    def docs_enabled(self) -> bool:
        """Interactive API docs are disabled in production."""
        return self.environment != "production"

    @model_validator(mode="after")
    def _require_secrets_outside_local(self) -> Self:
        if self.environment in ("staging", "production"):
            required = {
                "REDIS_URL": self.redis_url,
                "AI_HMAC_SECRET_LARAVEL_TO_AI": self.hmac_secret_laravel_to_ai,
                "AI_HMAC_SECRET_AI_TO_LARAVEL": self.hmac_secret_ai_to_laravel,
            }
            missing = [name for name, value in required.items() if not _has_value(value)]
            if missing:
                raise ValueError("Missing required settings: " + ", ".join(missing))
        return self


def _has_value(secret: SecretStr | None) -> bool:
    return secret is not None and bool(secret.get_secret_value())


@lru_cache
def get_settings() -> Settings:
    return Settings()
