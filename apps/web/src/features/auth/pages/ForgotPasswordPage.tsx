import { useMutation } from '@tanstack/react-query'
import { useState } from 'react'
import type { FormEvent } from 'react'
import { Link } from 'react-router-dom'
import { Alert } from '@/shared/ui/Alert'
import { Button } from '@/shared/ui/Button'
import { TextField } from '@/shared/ui/TextField'
import { requestPasswordReset } from '../api/auth'
import { toFormFailure } from '../errors'

export function ForgotPasswordPage() {
  const [email, setEmail] = useState('')
  const mutation = useMutation({ mutationFn: requestPasswordReset })
  const failure = mutation.isError ? toFormFailure(mutation.error) : null

  const onSubmit = (event: FormEvent) => {
    event.preventDefault()
    mutation.mutate(email)
  }

  return (
    <>
      <h1 className="font-display text-4xl font-semibold tracking-tight">Reset your password</h1>
      <p className="mt-2 text-muted">
        Enter your email and we will send you a link to choose a new password.
      </p>

      {mutation.isSuccess ? (
        <Alert tone="success" className="mt-8">
          If an account exists for {email}, a reset link is on its way. It expires in 60 minutes.
        </Alert>
      ) : (
        <form onSubmit={onSubmit} noValidate className="mt-8 space-y-5">
          {failure?.form && <Alert tone="error">{failure.form}</Alert>}
          <TextField
            label="Email"
            type="email"
            autoComplete="email"
            required
            value={email}
            onChange={(e) => setEmail(e.target.value)}
            error={failure?.fields.email}
          />
          <Button type="submit" loading={mutation.isPending} className="w-full">
            Send reset link
          </Button>
        </form>
      )}

      <p className="mt-8 text-sm">
        <Link to="/login" className="font-semibold underline underline-offset-4">
          Back to sign in
        </Link>
      </p>
    </>
  )
}
