import { useId } from 'react'
import { cn } from '@/shared/lib/cn'

/**
 * The product description, which later steers the video. A plain text area with a live character
 * count and a short prompt for what makes a useful description.
 */
export function DescriptionEditor({
  value,
  maxLength,
  onChange,
}: {
  value: string
  maxLength: number
  onChange: (value: string) => void
}) {
  const id = useId()
  const hintId = `${id}-hint`
  const remaining = maxLength - value.length
  const nearLimit = remaining <= Math.max(50, maxLength * 0.05)

  return (
    <div>
      <label htmlFor={id} className="mb-1.5 block text-sm font-medium">
        Product description
      </label>
      <textarea
        id={id}
        value={value}
        rows={8}
        maxLength={maxLength}
        aria-describedby={hintId}
        onChange={(event) => onChange(event.target.value)}
        placeholder="A hand-poured soy candle in a matte black jar, scented with cedar and smoked vanilla. Slow, warm, evening mood."
        className="block w-full resize-y rounded-lg border border-line bg-raised px-3.5 py-3 text-base leading-relaxed text-ink placeholder:text-muted/70"
      />
      <div className="mt-1.5 flex flex-wrap items-start justify-between gap-x-4 gap-y-1">
        <p id={hintId} className="max-w-prose text-sm text-muted">
          Say what it is, who it is for and the mood you want. Mention materials, colours and size
          if they matter.
        </p>
        <p
          aria-live={nearLimit ? 'polite' : 'off'}
          className={cn('text-sm tabular-nums', nearLimit ? 'font-medium text-warn' : 'text-muted')}
        >
          {value.length} / {maxLength}
          <span className="sr-only"> characters</span>
        </p>
      </div>
    </div>
  )
}
