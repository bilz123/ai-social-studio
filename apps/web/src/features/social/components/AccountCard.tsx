import { useState } from 'react'
import { timeAgo } from '@/shared/lib/time'
import { Badge } from '@/shared/ui/Badge'
import { Button } from '@/shared/ui/Button'
import { PlatformMark } from '@/shared/ui/PlatformMark'
import { Switch } from '@/shared/ui/Switch'
import type { PlatformId, SocialAccount } from '../api/social'
import { PLATFORM_COPY, accountBadge, profileSummary, tokenHint } from '../describe'
import { useCheckAccount, useDisconnectAccount, useToggleAccount } from '../useSocial'

/** A connected Page, profile or channel, with its health and the actions the person can take. */
export function AccountCard({
  account,
  reconnecting,
  onReconnect,
}: {
  account: SocialAccount
  reconnecting: boolean
  onReconnect: (platform: PlatformId) => void
}) {
  const check = useCheckAccount()
  const toggle = useToggleAccount()
  const disconnect = useDisconnectAccount()
  const [confirming, setConfirming] = useState(false)

  const badge = accountBadge(account)
  const hint = tokenHint(account)
  const summary = profileSummary(account)
  const platformName = PLATFORM_COPY[account.platform].name

  return (
    <li className="px-5 py-4" aria-label={`${platformName} account ${account.name}`}>
      <div className="flex items-start gap-3">
        {account.avatar_url ? (
          <img
            src={account.avatar_url}
            alt=""
            referrerPolicy="no-referrer"
            className="size-10 shrink-0 rounded-full bg-sunken object-cover"
          />
        ) : (
          <PlatformMark platform={account.platform} className="size-10" />
        )}
        <div className="min-w-0 flex-1">
          <div className="flex flex-wrap items-center gap-x-2 gap-y-1">
            <h3 className="truncate font-display text-base font-semibold">{account.name}</h3>
            <Badge tone={badge.tone}>{badge.label}</Badge>
          </div>
          <p className="mt-0.5 truncate text-sm text-muted">
            {[platformName, account.username, summary].filter(Boolean).join(' · ')}
          </p>
          {account.status_reason && (
            <p className="mt-2 text-sm font-medium text-warn">{account.status_reason}</p>
          )}
          {account.permissions.missing.length > 0 && account.status !== 'needs_reconnect' && (
            <p className="mt-1 text-sm text-muted">
              Missing permissions: {account.permissions.missing.join(', ')}
            </p>
          )}
          <p className="mt-2 text-sm text-muted">
            {[hint, account.last_checked_at ? `Checked ${timeAgo(account.last_checked_at)}.` : null]
              .filter(Boolean)
              .join(' ')}
          </p>
        </div>
      </div>

      {account.status !== 'needs_reconnect' && (
        <div className="mt-2 border-t border-line pt-1 sm:ml-13">
          <Switch
            label="Publishing"
            description={`Include ${account.name} when you publish a video.`}
            checked={account.is_enabled}
            onChange={(enabled) => toggle.mutate({ id: account.id, enabled })}
          />
        </div>
      )}

      {confirming ? (
        <div
          role="group"
          aria-label={`Confirm disconnecting ${account.name}`}
          className="mt-3 rounded-lg bg-danger-bg p-4 sm:ml-13"
        >
          <p className="text-sm font-medium text-danger">Disconnect {account.name}?</p>
          <p className="mt-1 text-sm text-ink">
            Saved access is deleted and you will need to sign in again to reconnect. Videos already
            published stay online.
          </p>
          <div className="mt-3 flex flex-wrap gap-2">
            <Button
              variant="danger"
              size="sm"
              loading={disconnect.isPending}
              onClick={() => disconnect.mutate({ id: account.id, name: account.name })}
            >
              Disconnect
            </Button>
            <Button variant="secondary" size="sm" onClick={() => setConfirming(false)}>
              Cancel
            </Button>
          </div>
        </div>
      ) : (
        <div className="mt-3 flex flex-wrap gap-2 sm:ml-13">
          {badge.needsAction && (
            <Button size="sm" loading={reconnecting} onClick={() => onReconnect(account.platform)}>
              Reconnect
            </Button>
          )}
          <Button
            variant="secondary"
            size="sm"
            loading={check.isPending}
            onClick={() => check.mutate(account.id)}
          >
            Check connection
          </Button>
          <Button variant="ghost" size="sm" onClick={() => setConfirming(true)}>
            Disconnect
          </Button>
        </div>
      )}
    </li>
  )
}
