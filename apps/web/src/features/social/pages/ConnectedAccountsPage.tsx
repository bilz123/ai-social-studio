import { Link2 } from 'lucide-react'
import { PageHeader } from '@/shared/layout/PageHeader'
import { Card, CardHeader } from '@/shared/ui/Card'
import { EmptyState } from '@/shared/ui/EmptyState'
import { ErrorState } from '@/shared/ui/ErrorState'
import { Skeleton } from '@/shared/ui/Skeleton'
import { AccountCard } from '../components/AccountCard'
import { PlatformCard } from '../components/PlatformCard'
import { useConnectPlatform, usePlatforms, useSocialAccounts } from '../useSocial'

export function ConnectedAccountsPage() {
  const platforms = usePlatforms()
  const accounts = useSocialAccounts()
  const connect = useConnectPlatform()

  const connectingId = connect.isPending ? connect.variables : undefined

  return (
    <>
      <PageHeader
        title="Connected Accounts"
        description="Link the Facebook, Instagram and YouTube accounts you publish to. Sign-in happens on each platform; we never see your passwords."
      />

      <section aria-labelledby="add-account-heading" className="mb-8">
        <h2
          id="add-account-heading"
          className="mb-3 font-display text-lg font-semibold tracking-tight"
        >
          Add an account
        </h2>
        {platforms.isPending && (
          <div role="status" aria-label="Loading platforms" className="grid gap-4 md:grid-cols-3">
            {Array.from({ length: 3 }, (_, index) => (
              <Skeleton key={index} className="h-44 rounded-xl" />
            ))}
          </div>
        )}
        {platforms.isError && (
          <Card>
            <ErrorState
              title="Could not load the platforms"
              onRetry={() => void platforms.refetch()}
            />
          </Card>
        )}
        {platforms.isSuccess && (
          <ul className="grid gap-4 md:grid-cols-3">
            {platforms.data.map((platform) => (
              <PlatformCard
                key={platform.id}
                platform={platform}
                connecting={connectingId === platform.id}
                disabled={connect.isPending}
                onConnect={() => connect.mutate(platform.id)}
              />
            ))}
          </ul>
        )}
      </section>

      <Card aria-labelledby="your-accounts-heading">
        <CardHeader
          id="your-accounts-heading"
          title="Your accounts"
          description="Everything you can publish to."
        />
        {accounts.isPending && (
          <div role="status" aria-label="Loading your accounts" className="divide-y divide-line">
            {Array.from({ length: 2 }, (_, index) => (
              <div key={index} className="flex items-center gap-3 px-5 py-4">
                <Skeleton className="size-10 rounded-full" />
                <div className="flex-1 space-y-2">
                  <Skeleton className="h-4 w-40" />
                  <Skeleton className="h-3 w-56" />
                </div>
              </div>
            ))}
          </div>
        )}
        {accounts.isError && (
          <ErrorState
            title="Could not load your accounts"
            onRetry={() => void accounts.refetch()}
          />
        )}
        {accounts.isSuccess && accounts.data.length === 0 && (
          <EmptyState
            icon={Link2}
            title="No accounts connected"
            description="Connect a platform above. Once an account is connected you can publish approved videos to it."
          />
        )}
        {accounts.isSuccess && accounts.data.length > 0 && (
          <ul className="divide-y divide-line">
            {accounts.data.map((account) => (
              <AccountCard
                key={account.id}
                account={account}
                reconnecting={connectingId === account.platform}
                onReconnect={(platform) => connect.mutate(platform)}
              />
            ))}
          </ul>
        )}
      </Card>
    </>
  )
}
