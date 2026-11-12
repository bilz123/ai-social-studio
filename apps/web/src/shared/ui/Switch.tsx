import { useId } from 'react'
import { cn } from '@/shared/lib/cn'

/** An on/off setting. Built on a real checkbox so keyboard and screen readers work natively. */
export function Switch({
  label,
  description,
  checked,
  onChange,
}: {
  label: string
  description?: string
  checked: boolean
  onChange: (checked: boolean) => void
}) {
  const id = useId()
  return (
    <div className="flex items-start justify-between gap-4 py-3">
      <label htmlFor={id} className="min-w-0 flex-1 cursor-pointer">
        <span className="block text-sm font-medium">{label}</span>
        {description && <span className="mt-0.5 block text-sm text-muted">{description}</span>}
      </label>
      <span className="relative mt-0.5 inline-flex h-6 w-10 shrink-0">
        <input
          id={id}
          type="checkbox"
          role="switch"
          checked={checked}
          onChange={(event) => onChange(event.target.checked)}
          className="peer absolute inset-0 z-10 size-full cursor-pointer opacity-0"
        />
        <span
          aria-hidden="true"
          className={cn(
            'absolute inset-0 rounded-full border border-line transition-colors',
            'peer-checked:border-transparent peer-checked:bg-brand',
            'bg-sunken peer-focus-visible:outline-2 peer-focus-visible:outline-offset-2 peer-focus-visible:outline-focus',
          )}
        />
        <span
          aria-hidden="true"
          className="absolute left-0.5 top-0.5 size-5 rounded-full bg-muted shadow-sm transition-transform peer-checked:translate-x-4 peer-checked:bg-brand-contrast"
        />
      </span>
    </div>
  )
}
