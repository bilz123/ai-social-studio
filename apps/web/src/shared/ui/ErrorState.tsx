import { CircleAlert } from 'lucide-react'
import { Button } from './Button'

export function ErrorState({
  title = 'Could not load this',
  description = 'Check your connection and try again.',
  onRetry,
}: {
  title?: string
  description?: string
  onRetry?: () => void
}) {
  return (
    <div role="alert" className="flex flex-col items-center px-6 py-10 text-center">
      <span className="grid size-11 place-items-center rounded-full bg-danger-bg text-danger">
        <CircleAlert aria-hidden="true" className="size-5" />
      </span>
      <h3 className="mt-4 font-display text-base font-semibold">{title}</h3>
      <p className="mt-1 max-w-sm text-sm text-muted">{description}</p>
      {onRetry && (
        <Button variant="secondary" size="sm" className="mt-5" onClick={onRetry}>
          Try again
        </Button>
      )}
    </div>
  )
}
