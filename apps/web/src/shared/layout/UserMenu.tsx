import { ChevronDown, LogOut, Settings } from 'lucide-react'
import { useRef, useState } from 'react'
import type { KeyboardEvent } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { useAuth } from '@/features/auth/useAuth'
import { useDismiss } from '@/shared/lib/useDismiss'
import { initials } from '@/shared/lib/time'
import { useToast } from '@/shared/toast/useToast'

export function UserMenu() {
  const { user, signOut } = useAuth()
  const navigate = useNavigate()
  const toast = useToast()
  const [open, setOpen] = useState(false)
  const triggerRef = useRef<HTMLButtonElement>(null)
  const menuRef = useRef<HTMLDivElement>(null)
  const containerRef = useDismiss<HTMLDivElement>(open, () => setOpen(false))

  if (!user) return null

  const close = (restoreFocus = false) => {
    setOpen(false)
    if (restoreFocus) triggerRef.current?.focus()
  }

  const items = () =>
    Array.from(menuRef.current?.querySelectorAll<HTMLElement>('[role="menuitem"]') ?? [])

  const onMenuKeyDown = (event: KeyboardEvent) => {
    const list = items()
    const index = list.indexOf(document.activeElement as HTMLElement)
    if (event.key === 'ArrowDown') list[(index + 1) % list.length]?.focus()
    else if (event.key === 'ArrowUp') list[(index - 1 + list.length) % list.length]?.focus()
    else if (event.key === 'Home') list[0]?.focus()
    else if (event.key === 'End') list[list.length - 1]?.focus()
    else if (event.key === 'Escape') close(true)
    else if (event.key === 'Tab') close()
    else return
    if (event.key !== 'Tab') event.preventDefault()
  }

  const onSignOut = async () => {
    close()
    try {
      await signOut()
      toast.show({ title: 'Signed out', description: 'See you next time.' })
    } finally {
      void navigate('/login', { replace: true })
    }
  }

  return (
    <div ref={containerRef} className="relative">
      <button
        ref={triggerRef}
        type="button"
        aria-haspopup="menu"
        aria-expanded={open}
        aria-label={`Account menu, ${user.name}`}
        onClick={() => setOpen((value) => !value)}
        onKeyDown={(event) => {
          if (event.key === 'ArrowDown' && !open) {
            event.preventDefault()
            setOpen(true)
            requestAnimationFrame(() => items()[0]?.focus())
          }
        }}
        className="flex min-h-10 items-center gap-2 rounded-lg py-1 pl-1 pr-2 transition-colors hover:bg-sunken"
      >
        <span
          aria-hidden="true"
          className="grid size-8 place-items-center rounded-full bg-brand text-xs font-bold text-brand-contrast"
        >
          {initials(user.name)}
        </span>
        <span className="hidden max-w-[10rem] truncate text-sm font-medium sm:block">
          {user.name}
        </span>
        <ChevronDown aria-hidden="true" className="hidden size-4 text-muted sm:block" />
      </button>

      {open && (
        <div
          ref={menuRef}
          role="menu"
          aria-label="Account"
          onKeyDown={onMenuKeyDown}
          className="pop absolute right-0 z-40 mt-2 w-64 rounded-xl border border-line bg-raised p-1.5 shadow-pop"
        >
          <div className="px-3 py-2">
            <p className="truncate text-sm font-semibold">{user.name}</p>
            <p className="truncate text-sm text-muted">{user.email}</p>
          </div>
          <div className="my-1 border-t border-line" />
          <Link
            role="menuitem"
            to="/settings"
            onClick={() => close()}
            className="flex min-h-10 items-center gap-2.5 rounded-lg px-3 text-sm hover:bg-sunken focus-visible:bg-sunken"
          >
            <Settings aria-hidden="true" className="size-4 text-muted" />
            Settings
          </Link>
          <button
            role="menuitem"
            type="button"
            onClick={() => void onSignOut()}
            className="flex min-h-10 w-full items-center gap-2.5 rounded-lg px-3 text-left text-sm hover:bg-sunken focus-visible:bg-sunken"
          >
            <LogOut aria-hidden="true" className="size-4 text-muted" />
            Sign out
          </button>
        </div>
      )}
    </div>
  )
}
