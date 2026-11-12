<?php

namespace App\Support\Problem;

use App\Http\Middleware\AssignRequestId;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Renders any exception on an API route as RFC 9457 problem+json:
 * {type, title, status, code, request_id, detail?, errors?}.
 *
 * Internals never leak: 5xx responses carry a generic message, and 403/404 details are fixed
 * strings. Only when APP_DEBUG is on does a 5xx include the exception class and message.
 */
class ProblemRenderer
{
    public static function render(Throwable $e, Request $request): JsonResponse
    {
        [$status, $code, $detail, $errors, $headers] = self::describe($e);

        $body = [
            'type' => 'about:blank',
            'title' => Response::$statusTexts[$status] ?? 'Error',
            'status' => $status,
            'code' => $code,
            'request_id' => $request->headers->get(AssignRequestId::HEADER),
        ];

        if ($detail !== null) {
            $body['detail'] = $detail;
        }

        if ($errors !== null) {
            $body['errors'] = $errors;
        }

        if ($status >= 500 && config('app.debug')) {
            $body['debug'] = ['exception' => $e::class, 'message' => $e->getMessage()];
        }

        $headers['Content-Type'] = 'application/problem+json';

        return response()->json($body, $status, $headers);
    }

    /**
     * @return array{0: int, 1: string, 2: string|null, 3: array<string, array<int, string>>|null, 4: array<string, string>}
     */
    private static function describe(Throwable $e): array
    {
        if ($e instanceof ValidationException) {
            return [422, 'validation_failed', 'The request is invalid.', $e->errors(), []];
        }

        if ($e instanceof AuthenticationException) {
            return [401, 'unauthenticated', 'Authentication is required.', null, []];
        }

        if ($e instanceof HttpExceptionInterface) {
            $status = $e->getStatusCode();

            $detail = match (true) {
                $status === 403 => 'This action is not permitted.',
                $status === 404 => 'The requested resource was not found.',
                $status >= 500 => 'An unexpected error occurred.',
                default => $e->getMessage() !== '' ? $e->getMessage() : null,
            };

            /** @var array<string, string> $headers */
            $headers = $e->getHeaders();

            return [$status, self::codeFor($status), $detail, null, $headers];
        }

        return [500, 'internal_error', 'An unexpected error occurred.', null, []];
    }

    private static function codeFor(int $status): string
    {
        return match ($status) {
            400 => 'bad_request',
            401 => 'unauthenticated',
            403 => 'forbidden',
            404 => 'not_found',
            405 => 'method_not_allowed',
            409 => 'conflict',
            419 => 'csrf_token_mismatch',
            422 => 'validation_failed',
            429 => 'rate_limited',
            503 => 'service_unavailable',
            default => $status >= 500 ? 'internal_error' : 'http_error',
        };
    }
}
