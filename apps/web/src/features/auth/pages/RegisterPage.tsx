import { useMutation } from '@tanstack/react-query'
import { useState } from 'react'
import type { FormEvent } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { Alert } from '@/shared/ui/Alert'
import { Button } from '@/shared/ui/Button'
import { TextField } from '@/shared/ui/TextField'
import { register } from '../api/auth'
import { toFormFailure } from '../errors'
import { useAuth } from '../useAuth'

export function RegisterPage() {
  const { signIn } = useAuth()
  const navigate = useNavigate()
  const [name, setName] = useState('')
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [confirmation, setConfirmation] = useState('')

  const mutation = useMutation({
    mutationFn: register,
    onSuccess: (user) => {
      signIn(user)
      void navigate('/dashboard', { replace: true })
    },
  })
  const failure = mutation.isError ? toFormFailure(mutation.error) : null

  const mismatch = confirmation !== '' && confirmation !== password

  const onSubmit = (event: FormEvent) => {
    event.preventDefault()
    if (mismatch) return
    mutation.mutate({ name, email, password, password_confirmation: confirmation })
  }

  return (
    <>
      <h1 className="font-display text-4xl font-semibold tracking-tight">Create your account</h1>
      <p className="mt-2 text-muted">Turn product photos into a ten-second video, ready to post.</p>

      <form onSubmit={onSubmit} noValidate className="mt-8 space-y-5">
        {failure?.form && <Alert tone="error">{failure.form}</Alert>}
        <TextField
          label="Full name"
          autoComplete="name"
          required
          value={name}
          onChange={(e) => setName(e.target.value)}
          error={failure?.fields.name}
        />
        <TextField
          label="Email"
          type="email"
          autoComplete="email"
          required
          value={email}
          onChange={(e) => setEmail(e.target.value)}
          error={failure?.fields.email}
        />
        <TextField
          label="Password"
          type="password"
          autoComplete="new-password"
          required
          value={password}
          onChange={(e) => setPassword(e.target.value)}
          hint="At least 12 characters with upper and lower case letters, a number and a symbol."
          error={failure?.fields.password}
        />
        <TextField
          label="Confirm password"
          type="password"
          autoComplete="new-password"
          required
          value={confirmation}
          onChange={(e) => setConfirmation(e.target.value)}
          error={mismatch ? 'The passwords do not match.' : undefined}
        />
        <Button type="submit" loading={mutation.isPending} className="w-full">
          Create account
        </Button>
      </form>

      <p className="mt-8 text-sm text-muted">
        Already have an account?{' '}
        <Link to="/login" className="font-semibold text-ink underline underline-offset-4">
          Sign in
        </Link>
      </p>
    </>
  )
}
