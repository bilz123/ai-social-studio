import { useMutation } from '@tanstack/react-query'
import { useState } from 'react'
import type { FormEvent } from 'react'
import { toFormFailure } from '@/features/auth/errors'
import { useToast } from '@/shared/toast/useToast'
import { Alert } from '@/shared/ui/Alert'
import { Button } from '@/shared/ui/Button'
import { TextField } from '@/shared/ui/TextField'
import { changePassword } from '../api/account'

export function PasswordForm() {
  const toast = useToast()
  const [current, setCurrent] = useState('')
  const [next, setNext] = useState('')
  const [confirmation, setConfirmation] = useState('')
  const mismatch = confirmation !== '' && confirmation !== next

  const mutation = useMutation({
    mutationFn: changePassword,
    onSuccess: () => {
      setCurrent('')
      setNext('')
      setConfirmation('')
      toast.show({
        tone: 'success',
        title: 'Password changed',
        description: 'Your other devices were signed out.',
      })
    },
  })
  const failure = mutation.isError ? toFormFailure(mutation.error) : null

  const onSubmit = (event: FormEvent) => {
    event.preventDefault()
    if (mismatch) return
    mutation.mutate({
      current_password: current,
      password: next,
      password_confirmation: confirmation,
    })
  }

  return (
    <form onSubmit={onSubmit} noValidate className="space-y-5">
      {failure?.form && <Alert tone="error">{failure.form}</Alert>}
      <TextField
        label="Current password"
        type="password"
        autoComplete="current-password"
        value={current}
        onChange={(e) => setCurrent(e.target.value)}
        error={failure?.fields.current_password}
      />
      <TextField
        label="New password"
        type="password"
        autoComplete="new-password"
        value={next}
        onChange={(e) => setNext(e.target.value)}
        hint="At least 12 characters with upper and lower case letters, a number and a symbol."
        error={failure?.fields.password}
      />
      <TextField
        label="Confirm new password"
        type="password"
        autoComplete="new-password"
        value={confirmation}
        onChange={(e) => setConfirmation(e.target.value)}
        error={mismatch ? 'The passwords do not match.' : undefined}
      />
      <Button type="submit" loading={mutation.isPending}>
        Change password
      </Button>
    </form>
  )
}
