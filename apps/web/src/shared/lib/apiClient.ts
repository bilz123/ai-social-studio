import { env } from './env'

/** RFC 9457 problem+json body returned by the Laravel API. */
export interface Problem {
  type?: string
  title?: string
  status?: number
  detail?: string
  code?: string
  errors?: Record<string, string[]>
  request_id?: string
}

export class ApiError extends Error {
  readonly status: number
  readonly code: string
  readonly requestId: string | null
  readonly errors: Record<string, string[]>

  constructor(init: {
    status: number
    code: string
    message: string
    requestId?: string | null
    errors?: Record<string, string[]>
  }) {
    super(init.message)
    this.name = 'ApiError'
    this.status = init.status
    this.code = init.code
    this.requestId = init.requestId ?? null
    this.errors = init.errors ?? {}
  }
}

export interface ApiRequestOptions {
  method?: 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE'
  /** Serialized as JSON when provided. */
  json?: unknown
  signal?: AbortSignal
  headers?: Record<string, string>
  /** Extra non-2xx statuses whose JSON body should be returned instead of thrown (e.g. 503). */
  okStatuses?: readonly number[]
  timeoutMs?: number
}

const DEFAULT_TIMEOUT_MS = 15_000

function joinUrl(path: string): string {
  return `${env.apiBaseUrl}/${path.replace(/^\/+/, '')}`
}

const CSRF_COOKIE = 'XSRF-TOKEN'
const CSRF_HEADER = 'X-XSRF-TOKEN'

function readCookie(name: string): string | null {
  const prefix = `${name}=`
  for (const part of document.cookie.split('; ')) {
    if (part.startsWith(prefix)) return decodeURIComponent(part.slice(prefix.length))
  }
  return null
}

/**
 * Laravel Sanctum's SPA protection: the API hands out an XSRF cookie, and every state-changing
 * request must echo it back in a header. The cookie is readable by design (it is not the session
 * and carries no authority on its own); the session cookie stays HttpOnly.
 */
async function refreshCsrfCookie(): Promise<void> {
  await fetch(joinUrl('sanctum/csrf-cookie'), {
    credentials: 'include',
    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
  })
}

async function parseBody(response: Response): Promise<unknown> {
  const text = await response.text()
  if (!text) return null
  try {
    return JSON.parse(text) as unknown
  } catch {
    return null
  }
}

/**
 * Fetch wrapper for the backend API.
 * - same-origin cookies are sent (`credentials: 'include'`) for the Sanctum SPA session;
 * - non-2xx responses become `ApiError`, parsed from problem+json when available;
 * - never reads or sends any secret: the browser only ever holds the session cookie.
 */
export async function apiFetch<T>(path: string, options: ApiRequestOptions = {}): Promise<T> {
  const { method = 'GET', json, signal, headers, okStatuses = [], timeoutMs } = options

  const controller = new AbortController()
  const timeout = setTimeout(() => controller.abort(), timeoutMs ?? DEFAULT_TIMEOUT_MS)
  const onAbort = () => controller.abort()
  signal?.addEventListener('abort', onAbort)

  const mutating = method !== 'GET'

  const send = async (): Promise<Response> =>
    fetch(joinUrl(path), {
      method,
      credentials: 'include',
      signal: controller.signal,
      headers: {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        ...(json !== undefined ? { 'Content-Type': 'application/json' } : {}),
        ...(mutating && readCookie(CSRF_COOKIE) ? { [CSRF_HEADER]: readCookie(CSRF_COOKIE) ?? '' } : {}),
        ...headers,
      },
      body: json !== undefined ? JSON.stringify(json) : undefined,
    })

  let response: Response
  try {
    if (mutating && !readCookie(CSRF_COOKIE)) await refreshCsrfCookie()
    response = await send()
    if (mutating && response.status === 419) {
      // The CSRF token expired or was rotated (for example after login): get a fresh one, retry once.
      await refreshCsrfCookie()
      response = await send()
    }
  } catch {
    const aborted = controller.signal.aborted
    throw new ApiError({
      status: 0,
      code: aborted ? 'timeout' : 'network_error',
      message: aborted ? 'The request timed out.' : 'Could not reach the server.',
      requestId: null,
    })
  } finally {
    clearTimeout(timeout)
    signal?.removeEventListener('abort', onAbort)
  }

  const body = await parseBody(response)

  if (response.ok || okStatuses.includes(response.status)) {
    return body as T
  }

  const problem = (body ?? {}) as Problem
  throw new ApiError({
    status: response.status,
    code: problem.code ?? 'http_error',
    message: problem.detail ?? problem.title ?? `Request failed with status ${response.status}.`,
    requestId: problem.request_id ?? response.headers.get('X-Request-Id'),
    errors: problem.errors,
  })
}
