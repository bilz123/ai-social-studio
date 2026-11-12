import { Film, FolderKanban, Link2, Send } from 'lucide-react'
import { Skeleton } from '@/shared/ui/Skeleton'
import { ErrorState } from '@/shared/ui/ErrorState'
import { useSampleStats } from '../data/useSample'
import { SampleBadge } from './SampleBadge'

export function StatCards() {
  const query = useSampleStats()

  if (query.isError) {
    return (
      <div className="rounded-xl border border-line bg-raised">
        <ErrorState title="Could not load your numbers" onRetry={() => void query.refetch()} />
      </div>
    )
  }

  const stats = query.data
  const tiles = stats
    ? [
        { label: 'Projects', icon: FolderKanban, ...stats.projects },
        { label: 'Videos generated', icon: Film, ...stats.videos },
        { label: 'Posts published', icon: Send, ...stats.published },
        { label: 'Connected accounts', icon: Link2, ...stats.accounts },
      ]
    : null

  return (
    <section aria-label="Overview" aria-busy={query.isPending}>
      <dl className="grid grid-cols-2 gap-3 lg:grid-cols-4 lg:gap-4">
        {tiles
          ? tiles.map(({ label, icon: Icon, value, note }) => (
              <div key={label} className="rounded-xl border border-line bg-raised p-4 sm:p-5">
                <div className="flex items-center justify-between gap-2">
                  <dt className="text-sm font-medium text-muted">{label}</dt>
                  <Icon aria-hidden="true" className="size-4 text-muted" />
                </div>
                <dd className="mt-3 font-display text-3xl font-semibold tabular-nums tracking-tight">
                  {value}
                </dd>
                <p className="mt-1 text-sm text-muted">{note}</p>
              </div>
            ))
          : Array.from({ length: 4 }, (_, index) => (
              <div key={index} className="rounded-xl border border-line bg-raised p-4 sm:p-5">
                <Skeleton className="h-4 w-24" />
                <Skeleton className="mt-4 h-8 w-16" />
                <Skeleton className="mt-3 h-4 w-28" />
              </div>
            ))}
      </dl>
      <div className="mt-2 flex justify-end">
        <SampleBadge />
      </div>
    </section>
  )
}
