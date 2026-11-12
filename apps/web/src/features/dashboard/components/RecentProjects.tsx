import { FolderKanban, Plus } from 'lucide-react'
import { Link } from 'react-router-dom'
import { timeAgo } from '@/shared/lib/time'
import { Badge } from '@/shared/ui/Badge'
import type { BadgeTone } from '@/shared/ui/Badge'
import { Card, CardHeader } from '@/shared/ui/Card'
import { buttonClasses } from '@/shared/ui/buttonStyles'
import { EmptyState } from '@/shared/ui/EmptyState'
import { ErrorState } from '@/shared/ui/ErrorState'
import { Skeleton } from '@/shared/ui/Skeleton'
import type { ProjectStatus } from '../data/sampleData'
import { useSampleProjects } from '../data/useSample'
import { SampleBadge } from './SampleBadge'

const STATUS: Record<ProjectStatus, { label: string; tone: BadgeTone }> = {
  draft: { label: 'Draft', tone: 'neutral' },
  generating: { label: 'Generating', tone: 'info' },
  review: { label: 'In review', tone: 'warn' },
  published: { label: 'Published', tone: 'ok' },
}

export function RecentProjects() {
  const query = useSampleProjects()

  return (
    <Card aria-labelledby="recent-projects" className="flex flex-col lg:col-span-2">
      <CardHeader
        id="recent-projects"
        title="Recent projects"
        description="Product photos and descriptions you are working on."
        action={<SampleBadge />}
      />
      {query.isPending && (
        <div role="status" aria-label="Loading recent projects" className="divide-y divide-line">
          {Array.from({ length: 3 }, (_, index) => (
            <div key={index} className="flex items-center gap-3 px-5 py-4">
              <Skeleton className="size-10 rounded-lg" />
              <div className="flex-1 space-y-2">
                <Skeleton className="h-4 w-1/2" />
                <Skeleton className="h-3 w-1/3" />
              </div>
            </div>
          ))}
        </div>
      )}
      {query.isError && (
        <ErrorState title="Could not load projects" onRetry={() => void query.refetch()} />
      )}
      {query.isSuccess && query.data.length === 0 && (
        <EmptyState
          icon={FolderKanban}
          title="No projects yet"
          description="A project holds the photos and description for one product."
          action={
            <Link to="/create" className={buttonClasses('primary', 'sm')}>
              <Plus aria-hidden="true" className="size-4" />
              New project
            </Link>
          }
        />
      )}
      {query.isSuccess && query.data.length > 0 && (
        <ul className="divide-y divide-line">
          {query.data.map((project) => {
            const status = STATUS[project.status]
            return (
              <li key={project.id} className="flex items-center gap-3 px-5 py-3.5">
                <span
                  aria-hidden="true"
                  className="grid size-10 shrink-0 place-items-center rounded-lg bg-sunken text-muted"
                >
                  <FolderKanban className="size-[18px]" />
                </span>
                <div className="min-w-0 flex-1">
                  <p className="truncate text-sm font-medium">{project.title}</p>
                  <p className="text-sm text-muted">
                    {project.images} images · updated {timeAgo(project.updatedAt)}
                  </p>
                </div>
                <Badge tone={status.tone}>{status.label}</Badge>
              </li>
            )
          })}
        </ul>
      )}
    </Card>
  )
}
