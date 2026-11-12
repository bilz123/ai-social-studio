from __future__ import annotations

from pydantic import SecretStr
from studio_common.config import ServiceSettings


class DescriptionAnalyzerSettings(ServiceSettings):
    service_name: str = "description-analyzer"

    # Optional language model (any OpenAI-compatible chat-completions endpoint). Without it the
    # service uses deterministic rules. Never hardcode these values.
    llm_base_url: str | None = None
    llm_model: str | None = None
    llm_api_key: SecretStr | None = None
    llm_timeout_seconds: float = 30.0

    @property
    def llm_enabled(self) -> bool:
        return bool(self.llm_base_url and self.llm_model and self.llm_api_key)
