import { ShieldCheck } from 'lucide-react'
import { useQuery } from '@tanstack/react-query'
import { ACTION_LABELS } from '@/features/account/activityLabels'
import { fetchActivity } from '@/features/account/api/account'
import { Badge } from '@/shared/ui/Badge'
import { Card, CardHeader } from '@/shared/ui/Card'
import { EmptyState } from '@/shared/ui/EmptyState'
import { ErrorState } from '@/shared/ui/ErrorState'
import { Skeleton } from '@/shared/ui/Skeleton'

/** Real data: GET /api/v1/account/activity (the signed-in user's audit trail). */
export function AccountActivity() {
  const query = useQuery({ queryKey: ['account', 'activity'], queryFn: fetchActivity })

  return (
    <Card aria-labelledby="account-activity">
      <CardHeader
        id="account-activity"
        title="Account activity"
        description="Sign-ins and security changes on your account."
        action={<Badge tone="ok">Live</Badge>}
      />
      {query.isPending && (
        <div role="status" aria-label="Loading account activity" className="space-y-3 p-5">
          <Skeleton className="h-5" />
          <Skeleton className="h-5" />
          <Skeleton className="h-5" />
        </div>
      )}
      {query.isError && (
        <ErrorState title="Could not load your activity" onRetry={() => void query.refetch()} />
      )}
      {query.isSuccess && query.data.length === 0 && (
        <EmptyState
          icon={ShieldCheck}
          title="No activity yet"
          description="Security events will be listed here."
        />
      )}
      {query.isSuccess && query.data.length > 0 && (
        <ul className="divide-y divide-line">
          {query.data.slice(0, 6).map((entry, index) => (
            <li
              key={`${entry.created_at}-${index}`}
              className="flex flex-wrap justify-between gap-x-4 gap-y-1 px-5 py-3 text-sm"
            >
              <span className="font-medium">{ACTION_LABELS[entry.action] ?? entry.action}</span>
              <span className="text-muted">
                {entry.created_at ? new Date(entry.created_at).toLocaleString() : ''}
                {entry.ip ? ` · ${entry.ip}` : ''}
              </span>
            </li>
          ))}
        </ul>
      )}
    </Card>
  )
}
