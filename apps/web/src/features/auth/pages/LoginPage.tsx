import { useMutation } from '@tanstack/react-query'
import { useState } from 'react'
import type { FormEvent } from 'react'
import { Link, useLocation, useNavigate } from 'react-router-dom'
import { Alert } from '@/shared/ui/Alert'
import { Button } from '@/shared/ui/Button'
import { TextField } from '@/shared/ui/TextField'
import { login } from '../api/auth'
import { toFormFailure } from '../errors'
import { useAuth } from '../useAuth'

export function LoginPage() {
  const { signIn } = useAuth()
  const navigate = useNavigate()
  const location = useLocation()
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')

  const mutation = useMutation({
    mutationFn: login,
    onSuccess: (user) => {
      signIn(user)
      const from = (location.state as { from?: string } | null)?.from
      void navigate(from && from.startsWith('/') ? from : '/dashboard', { replace: true })
    },
  })
  const failure = mutation.isError ? toFormFailure(mutation.error) : null

  const onSubmit = (event: FormEvent) => {
    event.preventDefault()
    mutation.mutate({ email, password })
  }

  return (
    <>
      <h1 className="font-display text-4xl font-semibold tracking-tight">Welcome back</h1>
      <p className="mt-2 text-muted">Sign in to pick up your projects where you left them.</p>

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
        <TextField
          label="Password"
          type="password"
          autoComplete="current-password"
          required
          value={password}
          onChange={(e) => setPassword(e.target.value)}
          error={failure?.fields.password}
        />
        <div className="text-right text-sm">
          <Link to="/forgot-password" className="font-medium underline underline-offset-4">
            Forgot your password?
          </Link>
        </div>
        <Button type="submit" loading={mutation.isPending} className="w-full">
          Sign in
        </Button>
      </form>

      <p className="mt-8 text-sm text-muted">
        New here?{' '}
        <Link to="/register" className="font-semibold text-ink underline underline-offset-4">
          Create an account
        </Link>
      </p>
    </>
  )
}
