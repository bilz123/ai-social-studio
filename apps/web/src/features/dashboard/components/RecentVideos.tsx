import { CircleAlert, Clapperboard, Film, LoaderCircle, Play } from 'lucide-react'
import { Link } from 'react-router-dom'
import { timeAgo } from '@/shared/lib/time'
import { Badge } from '@/shared/ui/Badge'
import type { BadgeTone } from '@/shared/ui/Badge'
import { Card, CardHeader } from '@/shared/ui/Card'
import { buttonClasses } from '@/shared/ui/buttonStyles'
import { EmptyState } from '@/shared/ui/EmptyState'
import { ErrorState } from '@/shared/ui/ErrorState'
import { Skeleton } from '@/shared/ui/Skeleton'
import type { VideoStatus } from '../data/sampleData'
import { useSampleVideos } from '../data/useSample'
import { SampleBadge } from './SampleBadge'

const STATUS: Record<VideoStatus, { label: string; tone: BadgeTone }> = {
  ready: { label: 'Ready', tone: 'ok' },
  awaiting_approval: { label: 'Awaiting approval', tone: 'warn' },
  generating: { label: 'Generating', tone: 'info' },
  failed: { label: 'Failed', tone: 'danger' },
}

const clock = (seconds: number) => `0:${String(seconds).padStart(2, '0')}`

export function RecentVideos() {
  const query = useSampleVideos()

  return (
    <Card aria-labelledby="recent-videos" className="lg:col-span-2">
      <CardHeader
        id="recent-videos"
        title="Recent videos"
        description="Your latest ten-second clips."
        action={
          <div className="flex items-center gap-2">
            <SampleBadge />
            <Link to="/videos" className={buttonClasses('ghost', 'sm')}>
              View all
            </Link>
          </div>
        }
      />
      {query.isPending && (
        <div
          role="status"
          aria-label="Loading recent videos"
          className="grid grid-cols-2 gap-4 p-5 sm:grid-cols-4"
        >
          {Array.from({ length: 4 }, (_, index) => (
            <Skeleton key={index} className="aspect-[9/16] rounded-xl" />
          ))}
        </div>
      )}
      {query.isError && (
        <ErrorState title="Could not load videos" onRetry={() => void query.refetch()} />
      )}
      {query.isSuccess && query.data.length === 0 && (
        <EmptyState
          icon={Film}
          title="No videos yet"
          description="Upload product photos and a short description to make your first video."
          action={
            <Link to="/create" className={buttonClasses('primary', 'sm')}>
              <Clapperboard aria-hidden="true" className="size-4" />
              Create video
            </Link>
          }
        />
      )}
      {query.isSuccess && query.data.length > 0 && (
        <ul className="grid grid-cols-2 gap-4 p-5 sm:grid-cols-4">
          {query.data.map((video) => {
            const status = STATUS[video.status]
            return (
              <li key={video.id} className="min-w-0">
                <div
                  className="relative aspect-[9/16] overflow-hidden rounded-xl"
                  style={{
                    background: `linear-gradient(165deg, hsl(${video.hue} 70% 52%), hsl(${(video.hue + 50) % 360} 55% 18%))`,
                  }}
                >
                  <span className="absolute inset-0 grid place-items-center text-white/90">
                    {video.status === 'generating' ? (
                      <LoaderCircle
                        aria-hidden="true"
                        className="size-7 animate-spin motion-reduce:animate-none"
                      />
                    ) : video.status === 'failed' ? (
                      <CircleAlert aria-hidden="true" className="size-7" />
                    ) : (
                      <Play aria-hidden="true" className="size-7 fill-current" />
                    )}
                  </span>
                  <span className="absolute bottom-2 right-2 rounded bg-black/55 px-1.5 py-0.5 text-xs font-semibold tabular-nums text-white">
                    {clock(video.seconds)}
                  </span>
                </div>
                <p className="mt-2 truncate text-sm font-medium">{video.title}</p>
                <div className="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1">
                  <Badge tone={status.tone}>{status.label}</Badge>
                  <span className="text-xs text-muted">{timeAgo(video.createdAt)}</span>
                </div>
              </li>
            )
          })}
        </ul>
      )}
    </Card>
  )
}
