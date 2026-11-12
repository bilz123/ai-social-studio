import { apiFetch } from '@/shared/lib/apiClient'

export type PlatformId = 'facebook' | 'instagram' | 'youtube'

export const PLATFORM_IDS: readonly PlatformId[] = ['facebook', 'instagram', 'youtube']

export const isPlatformId = (value: string | undefined): value is PlatformId =>
  PLATFORM_IDS.some((id) => id === value)

export type AccountStatus = 'active' | 'needs_reconnect' | 'revoked' | 'restricted'

export interface SocialPlatform {
  id: PlatformId
  name: string
  provider: string
  /** False until an administrator adds the platform's developer credentials. */
  configured: boolean
  supports_refresh: boolean
  required_permissions: string[]
  connected_count: number
  /** Names of the missing environment variables. Only sent outside production. */
  missing_configuration?: string[]
}

export interface SocialAccount {
  id: string
  platform: PlatformId
  provider: string
  name: string
  username: string | null
  avatar_url: string | null
  status: AccountStatus
  status_reason: string | null
  is_enabled: boolean
  capabilities: Record<string, boolean | number | string | null>
  profile: Record<string, number | string | null>
  permissions: { granted: string[]; missing: string[] }
  token: {
    present: boolean
    expires_at: string | null
    has_refresh_token: boolean
    last_error: string | null
  }
  connected_at: string | null
  last_checked_at: string | null
  last_publish_at: string | null
}

interface Wrapped<T> {
  data: T
}

const unwrap = <T>(body: Wrapped<T>): T => body.data

export const fetchPlatforms = () =>
  apiFetch<Wrapped<SocialPlatform[]>>('/v1/social/platforms').then(unwrap)

export const fetchAccounts = () =>
  apiFetch<Wrapped<SocialAccount[]>>('/v1/social/accounts').then(unwrap)

/** Asks the API for the provider consent URL; the caller then sends the browser there. */
export const startConnect = (platform: PlatformId) =>
  apiFetch<Wrapped<{ authorization_url: string; expires_at: string }>>(
    `/v1/social/${platform}/connect`,
    { method: 'POST' },
  ).then(unwrap)

/** Forwards what the provider put on the redirect URL; the API validates the state. */
export const completeConnect = (
  platform: PlatformId,
  input: { state: string; code?: string; error?: string },
) =>
  apiFetch<Wrapped<SocialAccount[]>>(`/v1/social/${platform}/callback`, {
    method: 'POST',
    json: input,
    // The provider exchange can take a few seconds.
    timeoutMs: 30_000,
  }).then(unwrap)

export const checkAccount = (id: string) =>
  apiFetch<Wrapped<SocialAccount>>(`/v1/social/accounts/${id}/check`, {
    method: 'POST',
    timeoutMs: 30_000,
  }).then(unwrap)

export const setAccountEnabled = (input: { id: string; enabled: boolean }) =>
  apiFetch<Wrapped<SocialAccount>>(`/v1/social/accounts/${input.id}`, {
    method: 'PATCH',
    json: { is_enabled: input.enabled },
  }).then(unwrap)

export const disconnectAccount = (id: string) =>
  apiFetch<null>(`/v1/social/accounts/${id}`, { method: 'DELETE', timeoutMs: 30_000 })
