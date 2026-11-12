"""Service-to-service request signing (HMAC-SHA256) and a request size limit.

The caller (Laravel) sends two headers:

    X-Timestamp: unix seconds
    X-Signature: sha256=<hex hmac of f"{timestamp}.{method}.{path}." + raw body>

Requests older (or newer) than the allowed skew are rejected, which limits replay. Health endpoints
are exempt so orchestrators can probe them without a secret.
"""

from __future__ import annotations

import hashlib
import hmac
import time

from starlette.datastructures import Headers
from starlette.types import ASGIApp, Message, Receive, Scope, Send

from studio_common.errors import problem_response
from studio_common.middleware import REQUEST_ID_HEADER

OPEN_PATHS = frozenset({"/healthz", "/readyz"})


def sign(secret: str, *, timestamp: int, method: str, path: str, body: bytes) -> str:
    message = f"{timestamp}.{method.upper()}.{path}.".encode() + body
    return "sha256=" + hmac.new(secret.encode(), message, hashlib.sha256).hexdigest()


class BodyTooLarge(Exception):
    pass


class SecurityMiddleware:
    """Rejects oversized bodies and unsigned requests before any route runs."""

    def __init__(
        self,
        app: ASGIApp,
        *,
        secret: str | None,
        max_body_bytes: int,
        max_skew_seconds: int,
    ) -> None:
        self.app = app
        self._secret = secret
        self._max_body = max_body_bytes
        self._max_skew = max_skew_seconds

    async def __call__(self, scope: Scope, receive: Receive, send: Send) -> None:
        if scope["type"] != "http":
            await self.app(scope, receive, send)
            return

        headers = Headers(scope=scope)
        request_id = (scope.get("state") or {}).get("request_id")
        declared = headers.get("content-length")
        if declared and declared.isdigit() and int(declared) > self._max_body:
            await self._reject(
                scope, receive, send, 413, request_id, "The request body is too large."
            )
            return

        needs_auth = self._secret is not None and scope["path"] not in OPEN_PATHS
        if not needs_auth and scope["method"] in ("GET", "HEAD", "OPTIONS"):
            await self.app(scope, receive, send)
            return

        # Read the body once (bounded), verify, then replay it to the app.
        chunks: list[bytes] = []
        size = 0
        more = True
        while more:
            message = await receive()
            if message["type"] == "http.disconnect":
                return
            chunk = message.get("body", b"")
            size += len(chunk)
            if size > self._max_body:
                await self._reject(
                    scope, receive, send, 413, request_id, "The request body is too large."
                )
                return
            chunks.append(chunk)
            more = message.get("more_body", False)
        body = b"".join(chunks)

        if needs_auth and not self._valid(headers, scope, body):
            await self._reject(
                scope, receive, send, 401, request_id, "Invalid or missing signature."
            )
            return

        sent = False

        async def replay() -> Message:
            nonlocal sent
            if not sent:
                sent = True
                return {"type": "http.request", "body": body, "more_body": False}
            return await receive()

        await self.app(scope, replay, send)

    def _valid(self, headers: Headers, scope: Scope, body: bytes) -> bool:
        if self._secret is None:
            return True
        raw_ts = headers.get("x-timestamp", "")
        signature = headers.get("x-signature", "")
        if not raw_ts.isdigit() or not signature:
            return False
        timestamp = int(raw_ts)
        if abs(time.time() - timestamp) > self._max_skew:
            return False
        expected = sign(
            self._secret,
            timestamp=timestamp,
            method=scope["method"],
            path=scope["path"],
            body=body,
        )
        return hmac.compare_digest(expected, signature)

    async def _reject(
        self,
        scope: Scope,
        receive: Receive,
        send: Send,
        status: int,
        request_id: str | None,
        detail: str,
    ) -> None:
        response = problem_response(status, request_id=request_id, detail=detail)
        if request_id:
            response.headers[REQUEST_ID_HEADER] = request_id
        await response(scope, receive, send)
