import { Badge } from '@/shared/ui/Badge'
import { Button } from '@/shared/ui/Button'
import { PlatformMark } from '@/shared/ui/PlatformMark'
import type { SocialPlatform } from '../api/social'
import { PLATFORM_COPY } from '../describe'

/** One connectable platform: what it does, whether the server is set up for it, and the connect button. */
export function PlatformCard({
  platform,
  connecting,
  disabled,
  onConnect,
}: {
  platform: SocialPlatform
  connecting: boolean
  /** Another connection is already starting. */
  disabled: boolean
  onConnect: () => void
}) {
  const copy = PLATFORM_COPY[platform.id]
  const noteId = `platform-note-${platform.id}`
  const hasAccounts = platform.connected_count > 0

  return (
    <li className="flex flex-col rounded-xl border border-line bg-raised p-5">
      <div className="flex items-start gap-3">
        <PlatformMark platform={platform.id} />
        <div className="min-w-0 flex-1">
          <h3 className="font-display text-base font-semibold">{copy.name}</h3>
          <p className="mt-0.5 text-sm text-muted">{copy.summary}</p>
        </div>
        {!platform.configured ? (
          <Badge>Not set up</Badge>
        ) : hasAccounts ? (
          <Badge tone="ok">{platform.connected_count} connected</Badge>
        ) : null}
      </div>

      <p id={noteId} className="mt-4 text-sm text-muted">
        {platform.configured
          ? copy.access
          : platform.missing_configuration && platform.missing_configuration.length > 0
            ? `This server has no ${copy.name} developer credentials yet. Missing: ${platform.missing_configuration.join(', ')}.`
            : `${copy.name} is not set up on this server yet. Ask an administrator to finish the setup.`}
      </p>

      <div className="mt-5 flex-1" />
      <Button
        variant={hasAccounts ? 'secondary' : 'primary'}
        loading={connecting}
        disabled={!platform.configured || disabled}
        aria-describedby={noteId}
        onClick={onConnect}
      >
        {hasAccounts ? `Add another ${copy.name} account` : `Connect ${copy.name}`}
      </Button>
    </li>
  )
}
