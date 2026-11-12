import { Link2 } from 'lucide-react'
import { Link } from 'react-router-dom'
import { PLATFORM_COPY, accountBadge } from '@/features/social/describe'
import { useSocialAccounts } from '@/features/social/useSocial'
import { Badge } from '@/shared/ui/Badge'
import { Card, CardHeader } from '@/shared/ui/Card'
import { buttonClasses } from '@/shared/ui/buttonStyles'
import { EmptyState } from '@/shared/ui/EmptyState'
import { ErrorState } from '@/shared/ui/ErrorState'
import { PlatformMark } from '@/shared/ui/PlatformMark'
import { Skeleton } from '@/shared/ui/Skeleton'

/** Real data: GET /api/v1/social/accounts. */
export function ConnectedAccounts() {
  const query = useSocialAccounts()

  return (
    <Card aria-labelledby="connected-accounts" className="flex flex-col">
      <CardHeader
        id="connected-accounts"
        title="Connected accounts"
        description="Where your videos can be posted."
        action={<Badge tone="ok">Live</Badge>}
      />
      {query.isPending && (
        <div role="status" aria-label="Loading connected accounts" className="divide-y divide-line">
          {Array.from({ length: 3 }, (_, index) => (
            <div key={index} className="flex items-center gap-3 px-5 py-3.5">
              <Skeleton className="size-9 rounded-lg" />
              <div className="flex-1 space-y-2">
                <Skeleton className="h-4 w-24" />
                <Skeleton className="h-3 w-32" />
              </div>
            </div>
          ))}
        </div>
      )}
      {query.isError && (
        <ErrorState title="Could not load accounts" onRetry={() => void query.refetch()} />
      )}
      {query.isSuccess && query.data.length === 0 && (
        <EmptyState
          icon={Link2}
          title="No accounts connected"
          description="Connect Facebook, Instagram or YouTube to publish your videos."
          action={
            <Link to="/accounts" className={buttonClasses('primary', 'sm')}>
              Connect an account
            </Link>
          }
        />
      )}
      {query.isSuccess && query.data.length > 0 && (
        <ul className="divide-y divide-line">
          {query.data.slice(0, 5).map((account) => {
            const badge = accountBadge(account)
            return (
              <li key={account.id} className="flex items-center gap-3 px-5 py-3.5">
                <PlatformMark platform={account.platform} />
                <div className="min-w-0 flex-1">
                  <p className="truncate text-sm font-medium">{account.name}</p>
                  <p className="truncate text-sm text-muted">
                    {PLATFORM_COPY[account.platform].name}
                    {account.username ? ` · ${account.username}` : ''}
                  </p>
                </div>
                <Badge tone={badge.tone}>{badge.label}</Badge>
              </li>
            )
          })}
        </ul>
      )}
      <div className="mt-auto border-t border-line p-3">
        <Link to="/accounts" className={buttonClasses('ghost', 'sm', 'w-full')}>
          Manage accounts
        </Link>
      </div>
    </Card>
  )
}
