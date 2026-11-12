import { useMutation } from '@tanstack/react-query'
import { useState } from 'react'
import type { FormEvent } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { Alert } from '@/shared/ui/Alert'
import { Button } from '@/shared/ui/Button'
import { TextField } from '@/shared/ui/TextField'
import { resetPassword } from '../api/auth'
import { toFormFailure } from '../errors'

export function ResetPasswordPage() {
  const [params] = useSearchParams()
  const token = params.get('token') ?? ''
  const email = params.get('email') ?? ''
  const [password, setPassword] = useState('')
  const [confirmation, setConfirmation] = useState('')

  const mutation = useMutation({ mutationFn: resetPassword })
  const failure = mutation.isError ? toFormFailure(mutation.error) : null
  const mismatch = confirmation !== '' && confirmation !== password

  if (!token || !email) {
    return (
      <>
        <h1 className="font-display text-4xl font-semibold tracking-tight">Link not valid</h1>
        <Alert tone="error" className="mt-8">
          This reset link is incomplete. Request a new one and use the link from the latest email.
        </Alert>
        <p className="mt-8 text-sm">
          <Link to="/forgot-password" className="font-semibold underline underline-offset-4">
            Request a new link
          </Link>
        </p>
      </>
    )
  }

  if (mutation.isSuccess) {
    return (
      <>
        <h1 className="font-display text-4xl font-semibold tracking-tight">Password updated</h1>
        <Alert tone="success" className="mt-8">
          Your password was changed and every other device was signed out.
        </Alert>
        <Link
          to="/login"
          className="mt-8 inline-flex min-h-11 items-center rounded-lg bg-brand px-5 text-sm font-semibold text-brand-contrast"
        >
          Sign in
        </Link>
      </>
    )
  }

  const onSubmit = (event: FormEvent) => {
    event.preventDefault()
    if (mismatch) return
    mutation.mutate({ token, email, password, password_confirmation: confirmation })
  }

  return (
    <>
      <h1 className="font-display text-4xl font-semibold tracking-tight">Choose a new password</h1>
      <p className="mt-2 text-muted">For {email}</p>

      <form onSubmit={onSubmit} noValidate className="mt-8 space-y-5">
        {failure?.form && <Alert tone="error">{failure.form}</Alert>}
        <TextField
          label="New password"
          type="password"
          autoComplete="new-password"
          required
          value={password}
          onChange={(e) => setPassword(e.target.value)}
          hint="At least 12 characters with upper and lower case letters, a number and a symbol."
          error={failure?.fields.password ?? failure?.fields.email}
        />
        <TextField
          label="Confirm new password"
          type="password"
          autoComplete="new-password"
          required
          value={confirmation}
          onChange={(e) => setConfirmation(e.target.value)}
          error={mismatch ? 'The passwords do not match.' : undefined}
        />
        <Button type="submit" loading={mutation.isPending} className="w-full">
          Update password
        </Button>
      </form>
    </>
  )
}
