import { ApiError } from '@/shared/lib/apiClient'

export interface FormFailure {
  /** First message per field, as returned by the API's validation errors. */
  fields: Record<string, string>
  /** Message for the whole form, shown when no field is to blame. */
  form: string | null
}

export function toFormFailure(error: unknown): FormFailure {
  if (error instanceof ApiError) {
    const fields = Object.fromEntries(
      Object.entries(error.errors).map(([name, messages]) => [name, messages[0] ?? '']),
    )
    if (Object.keys(fields).length > 0) return { fields, form: null }
    if (error.code === 'rate_limited')
      return { fields: {}, form: 'Too many attempts. Wait a minute and try again.' }
    if (error.status === 0) return { fields: {}, form: error.message }
    return { fields: {}, form: error.message }
  }
  return { fields: {}, form: 'Something went wrong. Try again.' }
}
