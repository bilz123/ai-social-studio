import { apiFetch } from '@/shared/lib/apiClient'

export interface LiveHealth {
  status: 'ok'
  service: string
  api_version: string
}

export interface CheckResult {
  status: 'ok' | 'fail'
  latency_ms?: number
}

export interface ReadyHealth {
  status: 'ok' | 'degraded'
  checks: Record<string, CheckResult>
}

export function fetchLiveHealth(signal?: AbortSignal): Promise<LiveHealth> {
  return apiFetch<LiveHealth>('/v1/health', { signal })
}

/** Readiness answers 503 with a JSON body when a dependency is down; that is data, not an error. */
export function fetchReadyHealth(signal?: AbortSignal): Promise<ReadyHealth> {
  return apiFetch<ReadyHealth>('/v1/health/ready', { signal, okStatuses: [503] })
}
