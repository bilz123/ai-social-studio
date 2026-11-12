import { apiFetch } from '@/shared/lib/apiClient'
import type { User } from '@/features/auth/api/auth'

export interface ActivityEntry {
  action: string
  ip: string | null
  user_agent: string | null
  created_at: string | null
}

export const updateProfile = (input: {
  name?: string
  email?: string
  current_password?: string
}) =>
  apiFetch<{ data: User }>('/v1/account/profile', { method: 'PATCH', json: input }).then(
    (body) => body.data,
  )

export const changePassword = (input: {
  current_password: string
  password: string
  password_confirmation: string
}) => apiFetch<null>('/v1/account/password', { method: 'PUT', json: input })

export const deleteAccount = (password: string) =>
  apiFetch<null>('/v1/account', { method: 'DELETE', json: { password } })

export const fetchActivity = () =>
  apiFetch<{ data: ActivityEntry[] }>('/v1/account/activity').then((body) => body.data)
