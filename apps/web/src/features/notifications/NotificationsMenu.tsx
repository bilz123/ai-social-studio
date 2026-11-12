import { Bell, BellOff } from 'lucide-react'
import { useState } from 'react'
import { useSampleNotifications } from '@/features/dashboard/data/useSample'
import { useDismiss } from '@/shared/lib/useDismiss'
import { timeAgo } from '@/shared/lib/time'
import { cn } from '@/shared/lib/cn'
import { Badge } from '@/shared/ui/Badge'
import { Button } from '@/shared/ui/Button'
import { EmptyState } from '@/shared/ui/EmptyState'
import { ErrorState } from '@/shared/ui/ErrorState'
import { Skeleton } from '@/shared/ui/Skeleton'
import { useToast } from '@/shared/toast/useToast'

const dot = { ok: 'bg-ok', warn: 'bg-warn', info: 'bg-muted' } as const

/**
 * Bell with an unread count and a panel of recent notifications.
 * PLACEHOLDER: the list is sample data and "read" state lives in memory only; the API has no
 * notifications endpoint yet.
 */
export function NotificationsMenu() {
  const [open, setOpen] = useState(false)
  const [readIds, setReadIds] = useState<ReadonlySet<string>>(new Set())
  const toast = useToast()
  const containerRef = useDismiss<HTMLDivElement>(open, () => setOpen(false))
  const query = useSampleNotifications()

  const items = query.data ?? []
  const unread = items.filter((item) => !readIds.has(item.id)).length

  const markAllRead = () => {
    setReadIds(new Set(items.map((item) => item.id)))
    toast.show({ tone: 'success', title: 'All notifications marked as read' })
  }

  return (
    <div ref={containerRef} className="relative">
      <button
        type="button"
        aria-haspopup="dialog"
        aria-expanded={open}
        aria-label={unread > 0 ? `Notifications, ${unread} unread` : 'Notifications'}
        onClick={() => setOpen((value) => !value)}
        className="relative grid size-10 place-items-center rounded-lg text-muted transition-colors hover:bg-sunken hover:text-ink"
      >
        <Bell aria-hidden="true" className="size-[18px]" />
        {unread > 0 && (
          <span
            aria-hidden="true"
            className="absolute right-2 top-2 size-2 rounded-full bg-accent ring-2 ring-surface"
          />
        )}
      </button>

      {open && (
        <div
          role="dialog"
          aria-label="Notifications"
          className="pop fixed inset-x-4 top-16 z-40 rounded-xl border border-line bg-raised shadow-pop sm:absolute sm:inset-x-auto sm:right-0 sm:top-full sm:mt-2 sm:w-96"
        >
          <div className="flex items-center justify-between gap-3 border-b border-line px-4 py-3">
            <h2 className="font-display text-base font-semibold">Notifications</h2>
            <div className="flex items-center gap-2">
              <Badge tone="warn">Sample data</Badge>
              <Button variant="ghost" size="sm" disabled={unread === 0} onClick={markAllRead}>
                Mark all read
              </Button>
            </div>
          </div>

          {query.isPending && (
            <div role="status" aria-label="Loading notifications" className="space-y-3 p-4">
              <Skeleton className="h-12" />
              <Skeleton className="h-12" />
              <Skeleton className="h-12" />
            </div>
          )}
          {query.isError && <ErrorState onRetry={() => void query.refetch()} />}
          {query.isSuccess && items.length === 0 && (
            <EmptyState
              icon={BellOff}
              title="You're all caught up"
              description="Video, approval and publishing updates will show up here."
            />
          )}
          {query.isSuccess && items.length > 0 && (
            <ul className="max-h-96 divide-y divide-line overflow-y-auto">
              {items.map((item) => {
                const isRead = readIds.has(item.id)
                return (
                  <li key={item.id} className="flex gap-3 px-4 py-3">
                    <span
                      aria-hidden="true"
                      className={cn(
                        'mt-1.5 size-2 shrink-0 rounded-full',
                        isRead ? 'bg-line' : dot[item.tone],
                      )}
                    />
                    <div className="min-w-0">
                      <p
                        className={cn(
                          'text-sm',
                          isRead ? 'font-medium text-muted' : 'font-semibold',
                        )}
                      >
                        {item.title}
                        {!isRead && <span className="sr-only"> (unread)</span>}
                      </p>
                      <p className="mt-0.5 text-sm text-muted">{item.body}</p>
                      <p className="mt-1 text-xs text-muted">{timeAgo(item.at)}</p>
                    </div>
                  </li>
                )
              })}
            </ul>
          )}
        </div>
      )}
    </div>
  )
}
