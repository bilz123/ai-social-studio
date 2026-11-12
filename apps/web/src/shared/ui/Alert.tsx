import type { ReactNode } from 'react'
import { cn } from '@/shared/lib/cn'

type Tone = 'error' | 'success' | 'info'

const tones: Record<Tone, string> = {
  error: 'bg-danger-bg text-danger',
  success: 'bg-ok-bg text-ok',
  info: 'bg-info-bg text-ink',
}

export function Alert({
  tone = 'info',
  children,
  className,
}: {
  tone?: Tone
  children: ReactNode
  className?: string
}) {
  return (
    <div
      role={tone === 'error' ? 'alert' : 'status'}
      className={cn('rounded-lg px-4 py-3 text-sm font-medium', tones[tone], className)}
    >
      {children}
    </div>
  )
}
