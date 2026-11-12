import type { LucideIcon } from 'lucide-react'
import type { ReactNode } from 'react'

export function EmptyState({
  icon: Icon,
  title,
  description,
  action,
}: {
  icon: LucideIcon
  title: string
  description: string
  action?: ReactNode
}) {
  return (
    <div className="flex flex-col items-center px-6 py-10 text-center">
      <span className="grid size-11 place-items-center rounded-full bg-sunken text-muted">
        <Icon aria-hidden="true" className="size-5" />
      </span>
      <h3 className="mt-4 font-display text-base font-semibold">{title}</h3>
      <p className="mt-1 max-w-sm text-sm text-muted">{description}</p>
      {action && <div className="mt-5">{action}</div>}
    </div>
  )
}
