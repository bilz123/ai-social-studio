import { apiFetch } from '@/shared/lib/apiClient'

export interface User {
  id: string
  name: string
  email: string
  email_verified: boolean
  email_verified_at: string | null
  roles: string[]
  permissions: string[]
  created_at: string | null
  last_login_at: string | null
}

interface Wrapped<T> {
  data: T
}

const unwrap = <T>(body: Wrapped<T>): T => body.data

export const fetchCurrentUser = () => apiFetch<Wrapped<User>>('/v1/auth/me').then(unwrap)

export const login = (input: { email: string; password: string }) =>
  apiFetch<Wrapped<User>>('/v1/auth/login', { method: 'POST', json: input }).then(unwrap)

export const register = (input: {
  name: string
  email: string
  password: string
  password_confirmation: string
}) => apiFetch<Wrapped<User>>('/v1/auth/register', { method: 'POST', json: input }).then(unwrap)

export const logout = () => apiFetch<null>('/v1/auth/logout', { method: 'POST' })

export const requestPasswordReset = (email: string) =>
  apiFetch<{ message: string }>('/v1/auth/forgot-password', { method: 'POST', json: { email } })

export const resetPassword = (input: {
  token: string
  email: string
  password: string
  password_confirmation: string
}) => apiFetch<{ message: string }>('/v1/auth/reset-password', { method: 'POST', json: input })

export const verifyEmail = (input: {
  id: string
  hash: string
  expires: string
  signature: string
}) => {
  const { id, hash, expires, signature } = input
  const query = new URLSearchParams({ expires, signature })
  return apiFetch<Wrapped<{ verified: boolean }>>(
    `/v1/auth/verify-email/${encodeURIComponent(id)}/${encodeURIComponent(hash)}?${query}`,
  )
}

export const resendVerification = () =>
  apiFetch<{ message: string }>('/v1/auth/email/verification-notification', { method: 'POST' })
