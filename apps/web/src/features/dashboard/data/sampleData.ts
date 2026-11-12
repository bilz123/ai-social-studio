/**
 * TEMPORARY PLACEHOLDER DATA.
 *
 * The API has no endpoints yet for projects, videos, connected accounts, publishing or
 * notifications, so the dashboard shows these sample records (and labels them "Sample data").
 * When an endpoint ships, replace the matching function in `useSample.ts` with a real
 * `apiFetch` call returning the same shape; the components do not change.
 *
 * Only the account activity list is real (GET /api/v1/account/activity).
 */

export type VideoStatus = 'ready' | 'awaiting_approval' | 'generating' | 'failed'
export type ProjectStatus = 'draft' | 'generating' | 'review' | 'published'
export type PlatformId = 'facebook' | 'instagram' | 'youtube'
export type ConnectionStatus = 'connected' | 'needs_reconnect' | 'not_connected'
export type PublishStatus = 'published' | 'scheduled' | 'failed'

export interface SampleStats {
  projects: { value: number; note: string }
  videos: { value: number; note: string }
  published: { value: number; note: string }
  accounts: { value: number; note: string }
}

export interface SampleProject {
  id: string
  title: string
  status: ProjectStatus
  images: number
  updatedAt: string
}

export interface SampleVideo {
  id: string
  title: string
  status: VideoStatus
  seconds: number
  createdAt: string
  /** Hue (0-360) used to tint the thumbnail until real frames exist. */
  hue: number
}

export interface SampleAccount {
  platform: PlatformId
  handle: string | null
  status: ConnectionStatus
}

export interface SamplePublishEvent {
  id: string
  video: string
  platform: PlatformId
  status: PublishStatus
  at: string
}

export interface SampleNotification {
  id: string
  title: string
  body: string
  at: string
  tone: 'ok' | 'warn' | 'info'
}

const ago = (minutes: number) => new Date(Date.now() - minutes * 60_000).toISOString()
const ahead = (minutes: number) => new Date(Date.now() + minutes * 60_000).toISOString()

export const sampleStats = (): SampleStats => ({
  projects: { value: 12, note: '3 in progress' },
  videos: { value: 28, note: '2 awaiting approval' },
  published: { value: 41, note: 'across 3 platforms' },
  accounts: { value: 3, note: '1 needs attention' },
})

export const sampleProjects = (): SampleProject[] => [
  { id: 'p1', title: 'Ceramic pour-over set', status: 'review', images: 6, updatedAt: ago(12) },
  { id: 'p2', title: 'Trail running vest', status: 'generating', images: 4, updatedAt: ago(48) },
  { id: 'p3', title: 'Walnut desk lamp', status: 'published', images: 8, updatedAt: ago(60 * 26) },
  { id: 'p4', title: 'Rose quartz serum', status: 'draft', images: 3, updatedAt: ago(60 * 52) },
]

export const sampleVideos = (): SampleVideo[] => [
  {
    id: 'v1',
    title: 'Pour-over set, slow orbit',
    status: 'awaiting_approval',
    seconds: 10,
    createdAt: ago(9),
    hue: 28,
  },
  {
    id: 'v2',
    title: 'Running vest, trail dawn',
    status: 'generating',
    seconds: 10,
    createdAt: ago(41),
    hue: 150,
  },
  {
    id: 'v3',
    title: 'Desk lamp, evening glow',
    status: 'ready',
    seconds: 10,
    createdAt: ago(60 * 25),
    hue: 265,
  },
  {
    id: 'v4',
    title: 'Serum, macro drip',
    status: 'failed',
    seconds: 10,
    createdAt: ago(60 * 50),
    hue: 340,
  },
]

export const sampleAccounts = (): SampleAccount[] => [
  { platform: 'facebook', handle: 'Northline Goods', status: 'connected' },
  { platform: 'instagram', handle: '@northline.goods', status: 'connected' },
  { platform: 'youtube', handle: 'Northline Goods', status: 'needs_reconnect' },
]

export const samplePublishing = (): SamplePublishEvent[] => [
  {
    id: 'e1',
    video: 'Desk lamp, evening glow',
    platform: 'instagram',
    status: 'published',
    at: ago(60 * 23),
  },
  {
    id: 'e2',
    video: 'Desk lamp, evening glow',
    platform: 'facebook',
    status: 'published',
    at: ago(60 * 23),
  },
  {
    id: 'e3',
    video: 'Desk lamp, evening glow',
    platform: 'youtube',
    status: 'failed',
    at: ago(60 * 22),
  },
  {
    id: 'e4',
    video: 'Pour-over set, slow orbit',
    platform: 'instagram',
    status: 'scheduled',
    at: ahead(60 * 18),
  },
]

export const sampleNotifications = (): SampleNotification[] => [
  {
    id: 'n1',
    title: 'Video ready for review',
    body: 'Pour-over set, slow orbit is waiting for your approval.',
    at: ago(9),
    tone: 'ok',
  },
  {
    id: 'n2',
    title: 'YouTube needs reconnecting',
    body: 'The connection expired. Reconnect to keep publishing.',
    at: ago(60 * 5),
    tone: 'warn',
  },
  {
    id: 'n3',
    title: 'Published to 2 accounts',
    body: 'Desk lamp, evening glow is live on Instagram and Facebook.',
    at: ago(60 * 23),
    tone: 'info',
  },
]
