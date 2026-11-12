import { useMutation } from '@tanstack/react-query'
import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { toFormFailure } from '@/features/auth/errors'
import { useAuth } from '@/features/auth/useAuth'
import { useToast } from '@/shared/toast/useToast'
import { Alert } from '@/shared/ui/Alert'
import { Button } from '@/shared/ui/Button'
import { TextField } from '@/shared/ui/TextField'
import { deleteAccount } from '../api/account'

export function DeleteAccount() {
  const { signOut } = useAuth()
  const navigate = useNavigate()
  const toast = useToast()
  const [open, setOpen] = useState(false)
  const [password, setPassword] = useState('')

  const mutation = useMutation({
    mutationFn: deleteAccount,
    onSuccess: async () => {
      await signOut().catch(() => undefined)
      toast.show({ title: 'Account deleted', description: 'Your data has been removed.' })
      void navigate('/login', { replace: true })
    },
  })
  const failure = mutation.isError ? toFormFailure(mutation.error) : null

  if (!open) {
    return (
      <Button variant="danger" onClick={() => setOpen(true)}>
        Delete my account
      </Button>
    )
  }

  return (
    <form
      onSubmit={(event) => {
        event.preventDefault()
        mutation.mutate(password)
      }}
      noValidate
      className="space-y-5"
    >
      <Alert tone="error">
        This permanently deletes your account, projects, videos and connected social accounts. It
        cannot be undone.
      </Alert>
      {failure?.form && <Alert tone="error">{failure.form}</Alert>}
      <TextField
        label="Confirm with your password"
        type="password"
        autoComplete="current-password"
        value={password}
        onChange={(e) => setPassword(e.target.value)}
        error={failure?.fields.password}
      />
      <div className="flex gap-3">
        <Button type="submit" variant="danger" loading={mutation.isPending}>
          Delete permanently
        </Button>
        <Button variant="secondary" onClick={() => setOpen(false)}>
          Cancel
        </Button>
      </div>
    </form>
  )
}
