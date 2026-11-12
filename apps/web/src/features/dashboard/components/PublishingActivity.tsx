import { CalendarClock, CircleAlert, CircleCheck, Send } from 'lucide-react'
import { Link } from 'react-router-dom'
import { cn } from '@/shared/lib/cn'
import { timeAgo } from '@/shared/lib/time'
import { Card, CardHeader } from '@/shared/ui/Card'
import { buttonClasses } from '@/shared/ui/buttonStyles'
import { EmptyState } from '@/shared/ui/EmptyState'
import { ErrorState } from '@/shared/ui/ErrorState'
import { Skeleton } from '@/shared/ui/Skeleton'
import type { PublishStatus } from '../data/sampleData'
import { useSamplePublishing } from '../data/useSample'
import { PLATFORM_NAMES } from '../data/platforms'
import { SampleBadge } from './SampleBadge'

const STATUS = {
  published: { label: 'Published', icon: CircleCheck, tone: 'text-ok' },
  scheduled: { label: 'Scheduled', icon: CalendarClock, tone: 'text-muted' },
  failed: { label: 'Failed', icon: CircleAlert, tone: 'text-danger' },
} satisfies Record<PublishStatus, { label: string; icon: typeof Send; tone: string }>

export function PublishingActivity() {
  const query = useSamplePublishing()

  return (
    <Card aria-labelledby="publishing-activity" className="flex flex-col">
      <CardHeader
        id="publishing-activity"
        title="Publishing activity"
        description="Recent posts and what is queued."
        action={<SampleBadge />}
      />
      {query.isPending && (
        <div role="status" aria-label="Loading publishing activity" className="space-y-4 p-5">
          {Array.from({ length: 4 }, (_, index) => (
            <div key={index} className="flex gap-3">
              <Skeleton className="size-5 rounded-full" />
              <div className="flex-1 space-y-2">
                <Skeleton className="h-4 w-3/4" />
                <Skeleton className="h-3 w-1/2" />
              </div>
            </div>
          ))}
        </div>
      )}
      {query.isError && (
        <ErrorState title="Could not load activity" onRetry={() => void query.refetch()} />
      )}
      {query.isSuccess && query.data.length === 0 && (
        <EmptyState
          icon={Send}
          title="Nothing published yet"
          description="Approved videos you publish or schedule will be listed here."
        />
      )}
      {query.isSuccess && query.data.length > 0 && (
        <ol className="space-y-4 p-5">
          {query.data.map((event) => {
            const status = STATUS[event.status]
            const Icon = status.icon
            return (
              <li key={event.id} className="flex gap-3">
                <Icon aria-hidden="true" className={cn('mt-0.5 size-5 shrink-0', status.tone)} />
                <div className="min-w-0">
                  <p className="truncate text-sm font-medium">{event.video}</p>
                  <p className="text-sm text-muted">
                    {status.label} on {PLATFORM_NAMES[event.platform]} · {timeAgo(event.at)}
                  </p>
                </div>
              </li>
            )
          })}
        </ol>
      )}
      <div className="mt-auto border-t border-line p-3">
        <Link to="/history" className={buttonClasses('ghost', 'sm', 'w-full')}>
          Open publishing history
        </Link>
      </div>
    </Card>
  )
}
