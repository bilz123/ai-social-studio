import { CircleAlert, CircleCheck, Info, X } from 'lucide-react'
import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import type { ReactNode } from 'react'
import { cn } from '@/shared/lib/cn'
import { ToastContext } from './toastContext'
import type { ToastApi, ToastOptions, ToastTone } from './toastContext'

interface ToastItem extends ToastOptions {
  id: number
  tone: ToastTone
}

const icons = { success: CircleCheck, error: CircleAlert, info: Info }
const iconTone: Record<ToastTone, string> = {
  success: 'text-ok',
  error: 'text-danger',
  info: 'text-muted',
}
const LIFETIME_MS: Record<ToastTone, number> = { success: 4500, info: 4500, error: 8000 }

function ToastCard({ toast, onDismiss }: { toast: ToastItem; onDismiss: (id: number) => void }) {
  const Icon = icons[toast.tone]
  const [paused, setPaused] = useState(false)

  useEffect(() => {
    if (paused) return
    const timer = window.setTimeout(() => onDismiss(toast.id), LIFETIME_MS[toast.tone])
    return () => window.clearTimeout(timer)
  }, [paused, toast.id, toast.tone, onDismiss])

  return (
    <li
      className="toast pointer-events-auto flex w-full max-w-sm items-start gap-3 rounded-xl border border-line bg-raised p-4 shadow-pop"
      onMouseEnter={() => setPaused(true)}
      onMouseLeave={() => setPaused(false)}
    >
      <Icon aria-hidden="true" className={cn('mt-0.5 size-5 shrink-0', iconTone[toast.tone])} />
      <div className="min-w-0 flex-1">
        <p className="text-sm font-semibold">{toast.title}</p>
        {toast.description && <p className="mt-0.5 text-sm text-muted">{toast.description}</p>}
      </div>
      <button
        type="button"
        onClick={() => onDismiss(toast.id)}
        aria-label="Dismiss notification"
        className="-m-1 rounded-md p-1 text-muted hover:text-ink"
      >
        <X aria-hidden="true" className="size-4" />
      </button>
    </li>
  )
}

/**
 * Transient confirmations. Errors are announced assertively, everything else politely; the
 * messages stay until dismissed or timed out, and pause while the pointer is over them.
 */
export function ToastProvider({ children }: { children: ReactNode }) {
  const [toasts, setToasts] = useState<ToastItem[]>([])
  const nextId = useRef(1)

  const dismiss = useCallback((id: number) => {
    setToasts((current) => current.filter((toast) => toast.id !== id))
  }, [])

  const show = useCallback((options: ToastOptions) => {
    const id = nextId.current++
    setToasts((current) => [...current.slice(-3), { ...options, id, tone: options.tone ?? 'info' }])
  }, [])

  const api = useMemo<ToastApi>(() => ({ show }), [show])
  const polite = toasts.filter((toast) => toast.tone !== 'error')
  const urgent = toasts.filter((toast) => toast.tone === 'error')

  return (
    <ToastContext.Provider value={api}>
      {children}
      <div className="pointer-events-none fixed inset-x-0 bottom-0 z-[60] flex flex-col items-center gap-2 p-4 sm:items-end sm:p-6">
        <ul
          role="status"
          aria-live="polite"
          className="flex w-full flex-col items-center gap-2 sm:items-end"
        >
          {polite.map((toast) => (
            <ToastCard key={toast.id} toast={toast} onDismiss={dismiss} />
          ))}
        </ul>
        <ul role="alert" className="flex w-full flex-col items-center gap-2 sm:items-end">
          {urgent.map((toast) => (
            <ToastCard key={toast.id} toast={toast} onDismiss={dismiss} />
          ))}
        </ul>
      </div>
    </ToastContext.Provider>
  )
}
