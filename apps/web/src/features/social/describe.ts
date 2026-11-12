import type { BadgeTone } from '@/shared/ui/Badge'
import type { PlatformId, SocialAccount } from './api/social'

export const PLATFORM_COPY: Record<PlatformId, { name: string; summary: string; access: string }> =
  {
    facebook: {
      name: 'Facebook',
      summary: 'Publish videos to the Facebook Pages you manage.',
      access: 'You choose which Pages to share. We can list them and post on them.',
    },
    instagram: {
      name: 'Instagram',
      summary: 'Publish Reels to an Instagram Business or Creator account.',
      access:
        'Personal Instagram accounts cannot be used. Switch the account type in Instagram first.',
    },
    youtube: {
      name: 'YouTube',
      summary: 'Upload videos to your YouTube channel.',
      access: 'Google asks you to allow uploads and reading your channel details.',
    },
  }

export interface AccountBadge {
  label: string
  tone: BadgeTone
  /** The person has to do something (reconnect, approve permissions). */
  needsAction: boolean
}

export function accountBadge(account: SocialAccount): AccountBadge {
  switch (account.status) {
    case 'needs_reconnect':
      return { label: 'Needs reconnect', tone: 'warn', needsAction: true }
    case 'restricted':
      return { label: 'Limited access', tone: 'warn', needsAction: true }
    case 'revoked':
      return { label: 'Disconnected', tone: 'neutral', needsAction: false }
    default:
      return account.is_enabled
        ? { label: 'Connected', tone: 'ok', needsAction: false }
        : { label: 'Paused', tone: 'neutral', needsAction: false }
  }
}

/** How long the saved access lasts, in plain words. */
export function tokenHint(account: SocialAccount, now: number = Date.now()): string | null {
  const { token } = account
  if (!token.present) return null
  if (token.expires_at === null) return 'Access does not expire.'

  const msLeft = new Date(token.expires_at).getTime() - now
  if (msLeft <= 0 && !token.has_refresh_token) return 'Access has expired.'
  if (token.has_refresh_token || account.platform === 'instagram')
    return 'Access renews automatically.'

  const days = Math.max(1, Math.ceil(msLeft / 86_400_000))
  return `Access expires in ${days} ${days === 1 ? 'day' : 'days'}.`
}

const count = (value: number | string | null | undefined, noun: string): string | null =>
  typeof value === 'number' ? `${value.toLocaleString()} ${noun}` : null

/** One line of provider facts under the account name. */
export function profileSummary(account: SocialAccount): string {
  const { profile } = account
  const parts: (string | null)[] =
    account.platform === 'youtube'
      ? [count(profile.subscribers, 'subscribers'), count(profile.videos, 'videos')]
      : account.platform === 'instagram'
        ? [count(profile.followers, 'followers'), count(profile.media_count, 'posts')]
        : [
            typeof profile.category === 'string' ? profile.category : null,
            count(profile.followers, 'followers'),
          ]

  return parts.filter((part): part is string => part !== null).join(' · ')
}
