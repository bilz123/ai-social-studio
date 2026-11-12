import { Film, FolderKanban, Link2, Send } from 'lucide-react'
import type { ReactNode } from 'react'
import { useProjectList } from '@/features/projects/useProjects'
import { useSocialAccounts } from '@/features/social/useSocial'
import { Badge } from '@/shared/ui/Badge'
import { Skeleton } from '@/shared/ui/Skeleton'
import { ErrorState } from '@/shared/ui/ErrorState'
import { useSampleStats } from '../data/useSample'

export function StatCards() {
  const query = useSampleStats()
  const accounts = useSocialAccounts()
  const projects = useProjectList(1)

  // The projects and connected accounts tiles are real; videos and posts are still sample numbers.
  const projectsTile: { value: ReactNode; note: string } = projects.isSuccess
    ? {
        value: projects.data.meta.total,
        note: projects.data.meta.total === 0 ? 'None yet' : 'Drafts and projects',
      }
    : projects.isError
      ? { value: '–', note: 'Could not load' }
      : { value: <Skeleton className="h-8 w-10" />, note: 'Loading…' }

  const accountsTile: { value: ReactNode; note: string } = accounts.isSuccess
    ? (() => {
        const needAttention = accounts.data.filter((account) => account.status !== 'active').length
        return {
          value: accounts.data.length,
          note:
            accounts.data.length === 0
              ? 'None connected yet'
              : needAttention > 0
                ? `${needAttention} need${needAttention === 1 ? 's' : ''} attention`
                : 'All working',
        }
      })()
    : accounts.isError
      ? { value: '–', note: 'Could not load' }
      : { value: <Skeleton className="h-8 w-10" />, note: 'Loading…' }

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
        { label: 'Projects', icon: FolderKanban, ...projectsTile },
        { label: 'Videos generated', icon: Film, ...stats.videos },
        { label: 'Posts published', icon: Send, ...stats.published },
        { label: 'Connected accounts', icon: Link2, ...accountsTile },
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
        <Badge tone="warn">Videos and posts are sample data</Badge>
      </div>
    </section>
  )
}
