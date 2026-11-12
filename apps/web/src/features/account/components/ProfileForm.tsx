import { useMutation } from '@tanstack/react-query'
import { useState } from 'react'
import type { FormEvent } from 'react'
import { toFormFailure } from '@/features/auth/errors'
import { useAuth } from '@/features/auth/useAuth'
import { useToast } from '@/shared/toast/useToast'
import { Alert } from '@/shared/ui/Alert'
import { Button } from '@/shared/ui/Button'
import { TextField } from '@/shared/ui/TextField'
import { updateProfile } from '../api/account'

export function ProfileForm() {
  const { user, refresh } = useAuth()
  const toast = useToast()
  const [name, setName] = useState(user?.name ?? '')
  const [email, setEmail] = useState(user?.email ?? '')
  const [currentPassword, setCurrentPassword] = useState('')
  const emailChanged = email.trim().toLowerCase() !== user?.email

  const mutation = useMutation({
    mutationFn: updateProfile,
    onSuccess: async () => {
      setCurrentPassword('')
      await refresh()
      toast.show({
        tone: 'success',
        title: 'Profile saved',
        description: emailChanged ? 'Check your inbox to verify the new address.' : undefined,
      })
    },
  })
  const failure = mutation.isError ? toFormFailure(mutation.error) : null

  const onSubmit = (event: FormEvent) => {
    event.preventDefault()
    mutation.mutate({ name, email, ...(emailChanged ? { current_password: currentPassword } : {}) })
  }

  return (
    <form onSubmit={onSubmit} noValidate className="space-y-5">
      {failure?.form && <Alert tone="error">{failure.form}</Alert>}
      <TextField
        label="Full name"
        autoComplete="name"
        value={name}
        onChange={(e) => setName(e.target.value)}
        error={failure?.fields.name}
      />
      <TextField
        label="Email"
        type="email"
        autoComplete="email"
        value={email}
        onChange={(e) => setEmail(e.target.value)}
        error={failure?.fields.email}
        hint={emailChanged ? 'Changing your email asks you to verify the new address.' : undefined}
      />
      {emailChanged && (
        <TextField
          label="Current password"
          type="password"
          autoComplete="current-password"
          value={currentPassword}
          onChange={(e) => setCurrentPassword(e.target.value)}
          error={failure?.fields.current_password}
        />
      )}
      <Button type="submit" loading={mutation.isPending}>
        Save changes
      </Button>
    </form>
  )
}
