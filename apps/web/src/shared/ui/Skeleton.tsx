import { cn } from '@/shared/lib/cn'

/** A shimmering placeholder block. Decorative: pair with a labelled busy region. */
export function Skeleton({ className }: { className?: string }) {
  return <div aria-hidden="true" className={cn('skeleton rounded-md', className)} />
}
