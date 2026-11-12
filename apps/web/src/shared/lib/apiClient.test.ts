import { afterEach, describe, expect, it, vi } from 'vitest'
import { ApiError, apiFetch } from './apiClient'

function jsonResponse(body: unknown, init: ResponseInit = {}): Response {
  return new Response(JSON.stringify(body), {
    status: 200,
    headers: { 'Content-Type': 'application/json' },
    ...init,
  })
}

afterEach(() => {
  vi.unstubAllGlobals()
})

describe('apiFetch', () => {
  it('requests under the API base URL with cookies and JSON headers', async () => {
    const fetchMock = vi.fn().mockResolvedValue(jsonResponse({ status: 'ok' }))
    vi.stubGlobal('fetch', fetchMock)

    const data = await apiFetch<{ status: string }>('/v1/health')

    expect(data).toEqual({ status: 'ok' })
    const [url, init] = fetchMock.mock.calls[0] as [string, RequestInit]
    expect(url).toBe('/api/v1/health')
    expect(init.credentials).toBe('include')
    expect(init.method).toBe('GET')
    expect(init.headers).toMatchObject({ Accept: 'application/json' })
  })

  it('serializes json bodies and sets the content type', async () => {
    const fetchMock = vi.fn().mockResolvedValue(jsonResponse({ ok: true }))
    vi.stubGlobal('fetch', fetchMock)

    await apiFetch('/v1/things', { method: 'POST', json: { name: 'x' } })

    const [, init] = fetchMock.mock.calls[0] as [string, RequestInit]
    expect(init.body).toBe('{"name":"x"}')
    expect(init.headers).toMatchObject({ 'Content-Type': 'application/json' })
  })

  it('turns problem+json responses into ApiError with code and request id', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn().mockResolvedValue(
        jsonResponse(
          {
            title: 'Not Found',
            status: 404,
            code: 'not_found',
            detail: 'No such thing.',
            request_id: 'req-123',
          },
          { status: 404, headers: { 'Content-Type': 'application/problem+json' } },
        ),
      ),
    )

    const error = await apiFetch('/v1/missing').catch((e: unknown) => e)

    expect(error).toBeInstanceOf(ApiError)
    expect(error).toMatchObject({
      status: 404,
      code: 'not_found',
      message: 'No such thing.',
      requestId: 'req-123',
    })
  })

  it('exposes validation errors', async () => {
    vi.stubGlobal(
      'fetch',
      vi
        .fn()
        .mockResolvedValue(
          jsonResponse(
            { code: 'validation_failed', errors: { email: ['Required.'] } },
            { status: 422 },
          ),
        ),
    )

    const error = (await apiFetch('/v1/x', { method: 'POST' }).catch((e: unknown) => e)) as ApiError

    expect(error.errors).toEqual({ email: ['Required.'] })
  })

  it('falls back gracefully when an error body is not JSON', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn().mockResolvedValue(new Response('<html>Bad gateway</html>', { status: 502 })),
    )

    const error = (await apiFetch('/v1/x').catch((e: unknown) => e)) as ApiError

    expect(error).toBeInstanceOf(ApiError)
    expect(error.status).toBe(502)
    expect(error.code).toBe('http_error')
  })

  it('returns the body for explicitly accepted non-2xx statuses', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn().mockResolvedValue(jsonResponse({ status: 'degraded', checks: {} }, { status: 503 })),
    )

    const data = await apiFetch<{ status: string }>('/v1/health/ready', { okStatuses: [503] })

    expect(data.status).toBe('degraded')
  })

  it('reports network failures as ApiError with status 0', async () => {
    vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new TypeError('Failed to fetch')))

    const error = (await apiFetch('/v1/x').catch((e: unknown) => e)) as ApiError

    expect(error).toBeInstanceOf(ApiError)
    expect(error).toMatchObject({ status: 0, code: 'network_error' })
  })

  it('reports a timeout when the request is aborted', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn(
        (_url: string, init: RequestInit) =>
          new Promise((_resolve, reject) => {
            init.signal?.addEventListener('abort', () => reject(new DOMException('Aborted')))
          }),
      ),
    )

    const error = (await apiFetch('/v1/slow', { timeoutMs: 10 }).catch(
      (e: unknown) => e,
    )) as ApiError

    expect(error).toMatchObject({ status: 0, code: 'timeout' })
  })
})
