import type { HTMLAttributes, ReactNode } from 'react'
import { cn } from '@/shared/lib/cn'

export function Card({ className, ...rest }: HTMLAttributes<HTMLElement>) {
  return <section className={cn('rounded-xl border border-line bg-raised', className)} {...rest} />
}

interface CardHeaderProps {
  title: string
  description?: string
  /** Right-aligned content: a badge, a link, a button. */
  action?: ReactNode
  /** Heading id so the card can be labelled by it. */
  id?: string
}

export function CardHeader({ title, description, action, id }: CardHeaderProps) {
  return (
    <div className="flex items-start justify-between gap-3 border-b border-line px-5 py-4">
      <div className="min-w-0">
        <h2 id={id} className="font-display text-base font-semibold tracking-tight">
          {title}
        </h2>
        {description && <p className="mt-0.5 text-sm text-muted">{description}</p>}
      </div>
      {action && <div className="shrink-0">{action}</div>}
    </div>
  )
}
