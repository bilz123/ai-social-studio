import { Link2 } from 'lucide-react'
import { Link } from 'react-router-dom'
import { Badge } from '@/shared/ui/Badge'
import type { BadgeTone } from '@/shared/ui/Badge'
import { Card, CardHeader } from '@/shared/ui/Card'
import { buttonClasses } from '@/shared/ui/buttonStyles'
import { EmptyState } from '@/shared/ui/EmptyState'
import { ErrorState } from '@/shared/ui/ErrorState'
import { Skeleton } from '@/shared/ui/Skeleton'
import type { ConnectionStatus } from '../data/sampleData'
import { useSampleAccounts } from '../data/useSample'
import { PLATFORM_NAMES } from '../data/platforms'
import { PlatformMark } from './PlatformMark'
import { SampleBadge } from './SampleBadge'

const STATUS: Record<ConnectionStatus, { label: string; tone: BadgeTone }> = {
  connected: { label: 'Connected', tone: 'ok' },
  needs_reconnect: { label: 'Reconnect', tone: 'warn' },
  not_connected: { label: 'Not connected', tone: 'neutral' },
}

export function ConnectedAccounts() {
  const query = useSampleAccounts()

  return (
    <Card aria-labelledby="connected-accounts" className="flex flex-col">
      <CardHeader
        id="connected-accounts"
        title="Connected accounts"
        description="Where your videos can be posted."
        action={<SampleBadge />}
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
        />
      )}
      {query.isSuccess && query.data.length > 0 && (
        <ul className="divide-y divide-line">
          {query.data.map((account) => {
            const status = STATUS[account.status]
            return (
              <li key={account.platform} className="flex items-center gap-3 px-5 py-3.5">
                <PlatformMark platform={account.platform} />
                <div className="min-w-0 flex-1">
                  <p className="text-sm font-medium">{PLATFORM_NAMES[account.platform]}</p>
                  <p className="truncate text-sm text-muted">{account.handle ?? 'Not connected'}</p>
                </div>
                <Badge tone={status.tone}>{status.label}</Badge>
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
