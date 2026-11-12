import { useId, useState } from 'react'
import type { InputHTMLAttributes } from 'react'
import { cn } from '@/shared/lib/cn'

interface TextFieldProps extends Omit<InputHTMLAttributes<HTMLInputElement>, 'id'> {
  label: string
  error?: string
  hint?: string
}

export function TextField({
  label,
  error,
  hint,
  type = 'text',
  className,
  ...rest
}: TextFieldProps) {
  const id = useId()
  const hintId = `${id}-hint`
  const errorId = `${id}-error`
  const isPassword = type === 'password'
  const [revealed, setRevealed] = useState(false)

  const describedBy = [hint ? hintId : null, error ? errorId : null].filter(Boolean).join(' ')

  return (
    <div className={className}>
      <label htmlFor={id} className="mb-1.5 block text-sm font-medium">
        {label}
      </label>
      <div className="relative">
        <input
          id={id}
          type={isPassword && revealed ? 'text' : type}
          aria-invalid={error ? true : undefined}
          aria-describedby={describedBy || undefined}
          className={cn(
            'block min-h-11 w-full rounded-lg border bg-raised px-3.5 text-base text-ink',
            'placeholder:text-muted/70',
            error ? 'border-danger' : 'border-line',
            isPassword && 'pr-16',
          )}
          {...rest}
        />
        {isPassword && (
          <button
            type="button"
            onClick={() => setRevealed((value) => !value)}
            aria-pressed={revealed}
            className="absolute inset-y-0 right-0 rounded-r-lg px-3.5 text-sm font-medium text-muted hover:text-ink"
          >
            {revealed ? 'Hide' : 'Show'}
            <span className="sr-only"> password</span>
          </button>
        )}
      </div>
      {hint && !error && (
        <p id={hintId} className="mt-1.5 text-sm text-muted">
          {hint}
        </p>
      )}
      {error && (
        <p id={errorId} className="mt-1.5 text-sm font-medium text-danger">
          {error}
        </p>
      )}
    </div>
  )
}
