import { cn } from '@/shared/lib/cn'
import type { PlatformId } from '../data/sampleData'

const marks: Record<PlatformId, string> = { facebook: 'Fb', instagram: 'Ig', youtube: 'Yt' }

/** Neutral monogram tile (the icon set has no brand logos; official marks come with the connect flow). */
export function PlatformMark({
  platform,
  className,
}: {
  platform: PlatformId
  className?: string
}) {
  return (
    <span
      aria-hidden="true"
      className={cn(
        'grid size-9 shrink-0 place-items-center rounded-lg bg-sunken font-display text-xs font-bold text-ink',
        className,
      )}
    >
      {marks[platform]}
    </span>
  )
}
