"""Environment-driven settings shared by every service. No credentials live in code."""

from __future__ import annotations

from typing import Literal, Self

from pydantic import AliasChoices, Field, SecretStr, model_validator
from pydantic_settings import BaseSettings, SettingsConfigDict

Environment = Literal["local", "testing", "staging", "production"]


def has_value(secret: SecretStr | None) -> bool:
    return secret is not None and bool(secret.get_secret_value())


class ServiceSettings(BaseSettings):
    """Settings every service understands. Services subclass this and add their own fields.

    All variables use the `AI_` prefix (for example `AI_ENVIRONMENT`), except `REDIS_URL`.
    """

    model_config = SettingsConfigDict(env_prefix="AI_", extra="ignore", case_sensitive=False)

    service_name: str = "service"
    environment: Environment = "local"
    log_level: str = "INFO"
    readiness_timeout_seconds: float = 2.5

    # Largest JSON request body accepted (bytes). Media never travels in request bodies.
    max_body_bytes: int = 256 * 1024

    # Service-to-service authentication: callers sign each request body with this secret.
    hmac_secret_laravel_to_ai: SecretStr | None = None
    hmac_max_skew_seconds: int = 300

    redis_url: SecretStr | None = Field(
        default=None, validation_alias=AliasChoices("REDIS_URL", "AI_REDIS_URL")
    )
    job_ttl_seconds: int = 7 * 24 * 3600
    max_concurrent_jobs: int = 2

    # Where media lives. `local` is a directory shared between services (a Docker volume).
    storage_backend: Literal["local", "s3"] = "local"
    storage_local_root: str = "/data/media"
    s3_endpoint: str | None = None
    s3_bucket: str | None = None
    s3_region: str = "us-east-1"
    s3_access_key: SecretStr | None = None
    s3_secret_key: SecretStr | None = None

    @property
    def docs_enabled(self) -> bool:
        """Interactive API docs are disabled in production."""
        return self.environment != "production"

    @property
    def auth_required(self) -> bool:
        return self.environment != "testing" or has_value(self.hmac_secret_laravel_to_ai)

    @model_validator(mode="after")
    def _require_secrets_outside_local(self) -> Self:
        if self.environment in ("staging", "production"):
            required = {"AI_HMAC_SECRET_LARAVEL_TO_AI": self.hmac_secret_laravel_to_ai}
            if self.storage_backend == "s3":
                required |= {
                    "AI_S3_ACCESS_KEY": self.s3_access_key,
                    "AI_S3_SECRET_KEY": self.s3_secret_key,
                }
            missing = [name for name, value in required.items() if not has_value(value)]
            if missing:
                raise ValueError("Missing required settings: " + ", ".join(missing))
            if self.storage_backend == "s3" and not (self.s3_bucket and self.s3_endpoint):
                raise ValueError("Missing required settings: AI_S3_BUCKET, AI_S3_ENDPOINT")
        return self
