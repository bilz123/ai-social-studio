import type { ReactNode } from 'react'
import { cn } from '@/shared/lib/cn'

export type BadgeTone = 'neutral' | 'ok' | 'warn' | 'danger' | 'info'

const tones: Record<BadgeTone, string> = {
  neutral: 'bg-sunken text-muted',
  ok: 'bg-ok-bg text-ok',
  warn: 'bg-warn-bg text-warn',
  danger: 'bg-danger-bg text-danger',
  info: 'bg-info-bg text-ink',
}

export function Badge({
  tone = 'neutral',
  children,
  className,
}: {
  tone?: BadgeTone
  children: ReactNode
  className?: string
}) {
  return (
    <span
      className={cn(
        'inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-semibold whitespace-nowrap',
        tones[tone],
        className,
      )}
    >
      {children}
    </span>
  )
}
