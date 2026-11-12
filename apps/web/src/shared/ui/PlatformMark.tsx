import { cn } from '@/shared/lib/cn'

export type PlatformMarkId = 'facebook' | 'instagram' | 'youtube'

const marks: Record<PlatformMarkId, string> = { facebook: 'Fb', instagram: 'Ig', youtube: 'Yt' }

/** Neutral monogram tile (the icon set has no brand logos; official marks need brand approval). */
export function PlatformMark({
  platform,
  className,
}: {
  platform: PlatformMarkId
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
