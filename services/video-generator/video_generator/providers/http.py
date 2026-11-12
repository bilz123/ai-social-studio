"""A generic REST video provider, for any service (or a thin gateway in front of one) that speaks:

    POST {submit_path}   {"image_base64", "prompt", "negative_prompt", "duration_seconds",
                          "aspect_ratio", "seed"}              -> {"id": "..."}
    GET  {status_path}   -> {"status": "queued|running|succeeded|failed", "output_url": "...",
                            "cost_cents": 12.5, "error": {"code", "message", "retryable"}}
    DELETE {cancel_path} -> 2xx

Vendor-specific APIs implement the same `VideoProvider` protocol in their own adapter module.
The API key is sent only in the `Authorization` header and is never logged.
"""

from __future__ import annotations

import base64
import ipaddress
import logging
from pathlib import Path
from urllib.parse import urlparse

import httpx

from video_generator.providers.base import (
    ImageToVideoRequest,
    ProviderCapabilities,
    ProviderError,
    ProviderJob,
    ProviderRejected,
    ProviderStatus,
)
from video_generator.settings import VideoGeneratorSettings

logger = logging.getLogger(__name__)

MAX_IMAGE_BYTES = 12 * 1024 * 1024


def download_url_allowed(url: str, allowed_hosts: set[str], *, allow_http: bool = False) -> bool:
    """Only fetch finished videos from public https hosts (or an explicit allowlist)."""
    parsed = urlparse(url)
    if parsed.scheme != "https" and not (allow_http and parsed.scheme == "http"):
        return False
    host = (parsed.hostname or "").lower()
    if not host:
        return False
    if allowed_hosts:
        return host in allowed_hosts
    try:
        address = ipaddress.ip_address(host)
    except ValueError:
        return host != "localhost" and not host.endswith((".local", ".internal"))
    return address.is_global


class HttpProvider:
    name = "http"

    def __init__(
        self, settings: VideoGeneratorSettings, client: httpx.AsyncClient | None = None
    ) -> None:
        if not (settings.video_http_base_url and settings.video_http_api_key):
            raise RuntimeError("The HTTP video provider is not configured")
        self._base = settings.video_http_base_url.rstrip("/")
        self._key = settings.video_http_api_key
        self._settings = settings
        self._hosts = {
            h.strip().lower() for h in settings.video_http_download_hosts.split(",") if h.strip()
        }
        self._allow_http = settings.environment in ("local", "testing")
        self._client = client or httpx.AsyncClient(timeout=30.0)
        self.capabilities = ProviderCapabilities(
            max_seconds=settings.video_http_max_seconds,
            aspect_ratios=("9:16", "1:1", "16:9"),
            cost_cents_per_second=settings.video_http_cost_cents_per_second,
        )

    def _headers(self) -> dict[str, str]:
        return {"Authorization": f"Bearer {self._key.get_secret_value()}"}

    def _classify(self, response: httpx.Response) -> ProviderError:
        status = response.status_code
        if status in (400, 422):
            return ProviderError("provider_invalid_request", "The provider refused the request.")
        if status in (401, 403):
            # A credentials problem is ours to fix; retrying will not help.
            return ProviderError("provider_auth_failed", "The provider rejected our credentials.")
        if status == 451:
            return ProviderRejected()
        return ProviderError(
            "provider_unavailable",
            "The provider is unavailable.",
            retryable=status == 429 or status >= 500,
        )

    async def submit(self, request: ImageToVideoRequest) -> ProviderJob:
        data = request.image_path.read_bytes()
        if len(data) > MAX_IMAGE_BYTES:
            raise ProviderError("image_too_large", "The image is too large for this provider.")
        body = {
            "image_base64": base64.b64encode(data).decode(),
            "prompt": request.prompt,
            "negative_prompt": request.negative_prompt,
            "duration_seconds": request.duration_seconds,
            "aspect_ratio": request.aspect_ratio,
            "seed": request.seed,
        }
        try:
            response = await self._client.post(
                self._base + self._settings.video_http_submit_path,
                json=body,
                headers=self._headers(),
            )
        except httpx.HTTPError as error:
            raise ProviderError(
                "provider_unavailable", "Could not reach the provider.", retryable=True
            ) from error
        if response.status_code >= 300:
            raise self._classify(response)
        try:
            return ProviderJob(id=str(response.json()["id"]), provider=self.name)
        except (KeyError, ValueError) as error:
            raise ProviderError(
                "provider_bad_response", "The provider sent an unexpected reply.", retryable=True
            ) from error

    async def poll(self, job: ProviderJob) -> ProviderStatus:
        url = self._base + self._settings.video_http_status_path.format(id=job.id)
        try:
            response = await self._client.get(url, headers=self._headers())
        except httpx.HTTPError as error:
            raise ProviderError(
                "provider_unavailable", "Could not reach the provider.", retryable=True
            ) from error
        if response.status_code >= 300:
            raise self._classify(response)
        try:
            data = response.json()
            state = data["status"]
        except (KeyError, ValueError) as error:
            raise ProviderError(
                "provider_bad_response", "The provider sent an unexpected reply.", retryable=True
            ) from error
        if state == "failed":
            err = data.get("error") or {}
            code = str(err.get("code", "provider_failed"))[:60]
            message = str(err.get("message", "The provider could not make the video."))[:200]
            if code == "provider_rejected" or code == "content_policy":
                return ProviderStatus(state="failed", error=ProviderRejected(message))
            return ProviderStatus(
                state="failed",
                error=ProviderError(code, message, retryable=bool(err.get("retryable", False))),
            )
        if state not in ("queued", "running", "succeeded"):
            raise ProviderError("provider_bad_response", "Unknown provider state.", retryable=True)
        return ProviderStatus(
            state=state,
            output_url=data.get("output_url"),
            cost_cents=float(data["cost_cents"]) if data.get("cost_cents") is not None else None,
        )

    async def fetch(self, job: ProviderJob, status: ProviderStatus, destination: Path) -> None:
        url = status.output_url or ""
        if not download_url_allowed(url, self._hosts, allow_http=self._allow_http):
            logger.warning("provider_download_blocked", extra={"job": job.id})
            raise ProviderError(
                "provider_bad_response", "The provider's download link was refused."
            )
        limit = self._settings.max_clip_bytes
        written = 0
        try:
            async with self._client.stream("GET", url, follow_redirects=False) as response:
                if response.status_code >= 300:
                    raise self._classify(response)
                with destination.open("wb") as handle:
                    async for chunk in response.aiter_bytes():
                        written += len(chunk)
                        if written > limit:
                            raise ProviderError("provider_bad_response", "The clip is too large.")
                        handle.write(chunk)
        except httpx.HTTPError as error:
            raise ProviderError(
                "provider_unavailable", "Download failed.", retryable=True
            ) from error

    async def cancel(self, job: ProviderJob) -> None:
        url = self._base + self._settings.video_http_cancel_path.format(id=job.id)
        try:
            await self._client.delete(url, headers=self._headers())
        except httpx.HTTPError:
            logger.warning("provider_cancel_failed", extra={"job": job.id})
