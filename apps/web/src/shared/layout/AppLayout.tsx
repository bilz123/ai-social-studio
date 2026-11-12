import { useState } from 'react'
import { NavLink, Outlet, useNavigate } from 'react-router-dom'
import { Alert } from '@/shared/ui/Alert'
import { Button } from '@/shared/ui/Button'
import { Logo } from '@/shared/ui/Logo'
import { cn } from '@/shared/lib/cn'
import { resendVerification } from '@/features/auth/api/auth'
import { useAuth } from '@/features/auth/useAuth'

const navLink = ({ isActive }: { isActive: boolean }) =>
  cn(
    'rounded-lg px-3 py-2 text-sm font-medium',
    isActive ? 'bg-sunken text-ink' : 'text-muted hover:text-ink',
  )

function VerifyEmailBanner() {
  const [sent, setSent] = useState(false)
  const [failed, setFailed] = useState(false)

  const resend = async () => {
    setFailed(false)
    try {
      await resendVerification()
      setSent(true)
    } catch {
      setFailed(true)
    }
  }

  if (sent) return <Alert tone="success">Verification email sent. Check your inbox.</Alert>
  return (
    <Alert tone={failed ? 'error' : 'info'} className="flex flex-wrap items-center gap-3">
      <span>
        {failed
          ? 'Could not send the email. Try again later.'
          : 'Verify your email address to secure your account.'}
      </span>
      <Button variant="secondary" className="min-h-9 px-3" onClick={() => void resend()}>
        Send verification email
      </Button>
    </Alert>
  )
}

export function AppLayout() {
  const { user, signOut } = useAuth()
  const navigate = useNavigate()
  const [signingOut, setSigningOut] = useState(false)

  const onSignOut = async () => {
    setSigningOut(true)
    try {
      await signOut()
    } finally {
      void navigate('/login', { replace: true })
    }
  }

  return (
    <div className="min-h-dvh">
      <header className="border-b border-line bg-raised">
        <div className="mx-auto flex max-w-5xl flex-wrap items-center gap-x-6 gap-y-2 px-4 py-3 sm:px-6">
          <Logo />
          <nav aria-label="Main" className="flex gap-1">
            <NavLink to="/dashboard" className={navLink}>
              Dashboard
            </NavLink>
            <NavLink to="/profile" className={navLink}>
              Profile
            </NavLink>
          </nav>
          <div className="ml-auto flex items-center gap-3">
            <span className="hidden text-sm text-muted sm:inline">{user?.name}</span>
            <Button
              variant="secondary"
              className="min-h-9 px-3"
              loading={signingOut}
              onClick={() => void onSignOut()}
            >
              Sign out
            </Button>
          </div>
        </div>
      </header>
      <main className="mx-auto max-w-5xl px-4 py-10 sm:px-6">
        {user && !user.email_verified && (
          <div className="mb-8">
            <VerifyEmailBanner />
          </div>
        )}
        <Outlet />
      </main>
    </div>
  )
}
