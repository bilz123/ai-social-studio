"""RFC 9457 problem+json error responses (same shape as the Laravel API)."""

from __future__ import annotations

import logging
from http import HTTPStatus
from typing import Any

from fastapi import FastAPI, Request
from fastapi.exceptions import RequestValidationError
from fastapi.responses import JSONResponse
from starlette.exceptions import HTTPException as StarletteHTTPException

PROBLEM_JSON = "application/problem+json"
REQUEST_ID_HEADER = "X-Request-Id"

logger = logging.getLogger(__name__)

_CODES = {
    400: "bad_request",
    401: "unauthenticated",
    403: "forbidden",
    404: "not_found",
    405: "method_not_allowed",
    409: "conflict",
    413: "payload_too_large",
    422: "validation_failed",
    429: "rate_limited",
    500: "internal_error",
    503: "service_unavailable",
}


class ServiceError(Exception):
    """A failure the caller should see: a stable `code` plus a message that is safe to show."""

    def __init__(self, status_code: int, code: str, detail: str, *, retryable: bool = False):
        super().__init__(detail)
        self.status_code = status_code
        self.code = code
        self.detail = detail
        self.retryable = retryable


def request_id_of(request: Request) -> str | None:
    state = request.scope.get("state") or {}
    value = state.get("request_id")
    return value if isinstance(value, str) else None


def problem_response(
    status_code: int,
    *,
    request_id: str | None,
    detail: str | None = None,
    errors: dict[str, list[str]] | None = None,
    headers: dict[str, str] | None = None,
    code: str | None = None,
    retryable: bool | None = None,
) -> JSONResponse:
    try:
        title = HTTPStatus(status_code).phrase
    except ValueError:
        title = "Error"
    body: dict[str, Any] = {
        "type": "about:blank",
        "title": title,
        "status": status_code,
        "code": code or _CODES.get(status_code, "error"),
        "request_id": request_id,
    }
    if detail:
        body["detail"] = detail
    if errors:
        body["errors"] = errors
    if retryable is not None:
        body["retryable"] = retryable
    response_headers = dict(headers or {})
    if request_id:
        response_headers[REQUEST_ID_HEADER] = request_id
    return JSONResponse(
        body, status_code=status_code, media_type=PROBLEM_JSON, headers=response_headers
    )


def register_exception_handlers(app: FastAPI) -> None:
    @app.exception_handler(ServiceError)
    async def service_error_handler(request: Request, exc: ServiceError) -> JSONResponse:
        return problem_response(
            exc.status_code,
            request_id=request_id_of(request),
            detail=exc.detail,
            code=exc.code,
            retryable=exc.retryable,
        )

    @app.exception_handler(StarletteHTTPException)
    async def http_exception_handler(request: Request, exc: StarletteHTTPException) -> JSONResponse:
        return problem_response(
            exc.status_code,
            request_id=request_id_of(request),
            detail=str(exc.detail) if exc.status_code < 500 else None,
            headers=dict(exc.headers) if exc.headers else None,
        )

    @app.exception_handler(RequestValidationError)
    async def validation_exception_handler(
        request: Request, exc: RequestValidationError
    ) -> JSONResponse:
        # Deliberately omit the submitted input: it may contain secrets.
        errors: dict[str, list[str]] = {}
        for error in exc.errors():
            location = ".".join(str(part) for part in error.get("loc", ()))
            errors.setdefault(location, []).append(str(error.get("msg", "Invalid value")))
        return problem_response(
            422,
            request_id=request_id_of(request),
            detail="The request is invalid.",
            errors=errors,
        )

    @app.exception_handler(Exception)
    async def unhandled_exception_handler(request: Request, exc: Exception) -> JSONResponse:
        request_id = request_id_of(request)
        logger.error(
            "unhandled_exception",
            exc_info=exc,
            extra={"request_id": request_id, "path": request.url.path},
        )
        return problem_response(500, request_id=request_id, detail="An unexpected error occurred.")
