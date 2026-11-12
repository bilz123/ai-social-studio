import { Menu, X } from 'lucide-react'
import { useCallback, useEffect, useRef, useState } from 'react'
import { Outlet } from 'react-router-dom'
import { resendVerification } from '@/features/auth/api/auth'
import { useAuth } from '@/features/auth/useAuth'
import { NotificationsMenu } from '@/features/notifications/NotificationsMenu'
import { useToast } from '@/shared/toast/useToast'
import { Alert } from '@/shared/ui/Alert'
import { Button } from '@/shared/ui/Button'
import { Logo } from '@/shared/ui/Logo'
import { Sidebar } from './Sidebar'
import { ThemeToggle } from './ThemeToggle'
import { UserMenu } from './UserMenu'

function VerifyEmailBanner() {
  const toast = useToast()
  const [sending, setSending] = useState(false)

  const resend = async () => {
    setSending(true)
    try {
      await resendVerification()
      toast.show({
        tone: 'success',
        title: 'Verification email sent',
        description: 'Check your inbox.',
      })
    } catch {
      toast.show({
        tone: 'error',
        title: 'Could not send the email',
        description: 'Try again in a few minutes.',
      })
    } finally {
      setSending(false)
    }
  }

  return (
    <Alert tone="info" className="flex flex-wrap items-center gap-3">
      <span>Verify your email address to secure your account.</span>
      <Button variant="secondary" size="sm" loading={sending} onClick={() => void resend()}>
        Send verification email
      </Button>
    </Alert>
  )
}

/** Mobile navigation: a modal drawer with a focus trap, Escape and scrim-click to close. */
function MobileDrawer({ open, onClose }: { open: boolean; onClose: () => void }) {
  const panelRef = useRef<HTMLDivElement>(null)

  useEffect(() => {
    if (!open) return
    const previous = document.activeElement as HTMLElement | null
    panelRef.current?.querySelector<HTMLElement>('button, a')?.focus()
    const onKey = (event: KeyboardEvent) => {
      if (event.key === 'Escape') onClose()
      if (event.key !== 'Tab' || !panelRef.current) return
      const focusable = panelRef.current.querySelectorAll<HTMLElement>(
        'a[href], button:not([disabled])',
      )
      const first = focusable[0]
      const last = focusable[focusable.length - 1]
      if (event.shiftKey && document.activeElement === first) {
        event.preventDefault()
        last?.focus()
      } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault()
        first?.focus()
      }
    }
    document.addEventListener('keydown', onKey)
    document.body.style.overflow = 'hidden'
    return () => {
      document.removeEventListener('keydown', onKey)
      document.body.style.overflow = ''
      previous?.focus()
    }
  }, [open, onClose])

  if (!open) return null
  return (
    <div className="fixed inset-0 z-50 lg:hidden">
      <div aria-hidden="true" className="absolute inset-0 bg-scrim" onClick={onClose} />
      <div
        ref={panelRef}
        role="dialog"
        aria-modal="true"
        aria-label="Navigation"
        className="pop absolute inset-y-0 left-0 w-72 max-w-[85vw] border-r border-line bg-surface shadow-pop"
      >
        <button
          type="button"
          onClick={onClose}
          aria-label="Close navigation"
          className="absolute right-3 top-3 grid size-10 place-items-center rounded-lg text-muted hover:bg-sunken hover:text-ink"
        >
          <X aria-hidden="true" className="size-5" />
        </button>
        <Sidebar onNavigate={onClose} />
      </div>
    </div>
  )
}

export function AppLayout() {
  const { user } = useAuth()
  const [drawerOpen, setDrawerOpen] = useState(false)
  const closeDrawer = useCallback(() => setDrawerOpen(false), [])

  return (
    <div className="min-h-dvh">
      <aside className="fixed inset-y-0 left-0 hidden w-64 border-r border-line bg-surface lg:block">
        <Sidebar />
      </aside>

      <div className="lg:pl-64">
        <header className="sticky top-0 z-30 flex h-16 items-center gap-2 border-b border-line bg-surface/90 px-4 backdrop-blur sm:px-6">
          <button
            type="button"
            onClick={() => setDrawerOpen(true)}
            aria-label="Open navigation"
            aria-expanded={drawerOpen}
            className="grid size-10 place-items-center rounded-lg text-muted hover:bg-sunken hover:text-ink lg:hidden"
          >
            <Menu aria-hidden="true" className="size-5" />
          </button>
          <div className="lg:hidden">
            <Logo />
          </div>
          <div className="ml-auto flex items-center gap-1">
            <ThemeToggle />
            <NotificationsMenu />
            <UserMenu />
          </div>
        </header>

        <main className="mx-auto max-w-6xl px-4 py-8 sm:px-6 lg:py-10">
          {user && !user.email_verified && (
            <div className="mb-8">
              <VerifyEmailBanner />
            </div>
          )}
          <Outlet />
        </main>
      </div>

      <MobileDrawer open={drawerOpen} onClose={closeDrawer} />
    </div>
  )
}
