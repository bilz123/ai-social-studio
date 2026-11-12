import { useMutation, useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import type { FormEvent } from 'react'
import { useNavigate } from 'react-router-dom'
import { Alert } from '@/shared/ui/Alert'
import { Button } from '@/shared/ui/Button'
import { TextField } from '@/shared/ui/TextField'
import { toFormFailure } from '@/features/auth/errors'
import { useAuth } from '@/features/auth/useAuth'
import { changePassword, deleteAccount, fetchActivity, updateProfile } from '../api/account'

const ACTION_LABELS: Record<string, string> = {
  'auth.register': 'Account created',
  'auth.login': 'Signed in',
  'auth.logout': 'Signed out',
  'auth.email_verified': 'Email verified',
  'auth.password_reset': 'Password reset',
  'account.profile_updated': 'Profile updated',
  'account.password_changed': 'Password changed',
}

function Section({
  title,
  description,
  children,
}: {
  title: string
  description: string
  children: React.ReactNode
}) {
  return (
    <section className="grid gap-6 border-t border-line py-10 md:grid-cols-[minmax(0,1fr)_minmax(0,1.6fr)]">
      <div>
        <h2 className="font-display text-xl font-semibold">{title}</h2>
        <p className="mt-1 text-sm text-muted">{description}</p>
      </div>
      <div>{children}</div>
    </section>
  )
}

function ProfileForm() {
  const { user, refresh } = useAuth()
  const [name, setName] = useState(user?.name ?? '')
  const [email, setEmail] = useState(user?.email ?? '')
  const [currentPassword, setCurrentPassword] = useState('')
  const emailChanged = email.trim().toLowerCase() !== user?.email

  const mutation = useMutation({
    mutationFn: updateProfile,
    onSuccess: async () => {
      setCurrentPassword('')
      await refresh()
    },
  })
  const failure = mutation.isError ? toFormFailure(mutation.error) : null

  const onSubmit = (event: FormEvent) => {
    event.preventDefault()
    mutation.mutate({ name, email, ...(emailChanged ? { current_password: currentPassword } : {}) })
  }

  return (
    <form onSubmit={onSubmit} noValidate className="space-y-5">
      {mutation.isSuccess && <Alert tone="success">Profile saved.</Alert>}
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

function PasswordForm() {
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
      {mutation.isSuccess && (
        <Alert tone="success">Password changed. Other devices were signed out.</Alert>
      )}
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

function ActivityList() {
  const query = useQuery({ queryKey: ['account', 'activity'], queryFn: fetchActivity })

  if (query.isPending) return <p className="text-sm text-muted">Loading activity…</p>
  if (query.isError) return <Alert tone="error">Could not load your activity.</Alert>
  if (query.data.length === 0) return <p className="text-sm text-muted">No activity yet.</p>

  return (
    <ul className="divide-y divide-line">
      {query.data.map((entry, index) => (
        <li
          key={`${entry.created_at}-${index}`}
          className="flex flex-wrap justify-between gap-x-4 py-3 text-sm"
        >
          <span className="font-medium">{ACTION_LABELS[entry.action] ?? entry.action}</span>
          <span className="text-muted">
            {entry.created_at ? new Date(entry.created_at).toLocaleString() : ''}
            {entry.ip ? ` from ${entry.ip}` : ''}
          </span>
        </li>
      ))}
    </ul>
  )
}

function DeleteAccount() {
  const { signOut } = useAuth()
  const navigate = useNavigate()
  const [open, setOpen] = useState(false)
  const [password, setPassword] = useState('')

  const mutation = useMutation({
    mutationFn: deleteAccount,
    onSuccess: async () => {
      await signOut().catch(() => undefined)
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

export function ProfilePage() {
  const { user } = useAuth()
  if (!user) return null

  return (
    <>
      <h1 className="font-display text-4xl font-semibold tracking-tight">Your profile</h1>
      <p className="mt-2 text-muted">
        {user.email_verified ? 'Email verified.' : 'Email not verified yet.'}
      </p>

      <div className="mt-8">
        <Section title="Details" description="Your name and sign-in email.">
          <ProfileForm key={user.email + user.name} />
        </Section>
        <Section title="Password" description="Changing it signs you out everywhere else.">
          <PasswordForm />
        </Section>
        <Section
          title="Recent activity"
          description="Sign-ins and security changes on your account."
        >
          <ActivityList />
        </Section>
        <Section title="Delete account" description="Remove your data from the studio.">
          <DeleteAccount />
        </Section>
      </div>
    </>
  )
}
