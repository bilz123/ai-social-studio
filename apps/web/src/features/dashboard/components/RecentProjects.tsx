import { FolderKanban, ImageOff, Plus } from 'lucide-react'
import { Link } from 'react-router-dom'
import { useProjectList } from '@/features/projects/useProjects'
import { timeAgo } from '@/shared/lib/time'
import { Badge } from '@/shared/ui/Badge'
import { Card, CardHeader } from '@/shared/ui/Card'
import { buttonClasses } from '@/shared/ui/buttonStyles'
import { EmptyState } from '@/shared/ui/EmptyState'
import { ErrorState } from '@/shared/ui/ErrorState'
import { Skeleton } from '@/shared/ui/Skeleton'

const SHOWN = 4

/** The projects you changed most recently (real data from the projects API). */
export function RecentProjects() {
  const query = useProjectList(1)

  return (
    <Card aria-labelledby="recent-projects" className="flex flex-col lg:col-span-2">
      <CardHeader
        id="recent-projects"
        title="Recent projects"
        description="Product photos and descriptions you are working on."
        action={
          <Link to="/create" className={buttonClasses('ghost', 'sm')}>
            View all
          </Link>
        }
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
      {query.isSuccess && query.data.data.length === 0 && (
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
      {query.isSuccess && query.data.data.length > 0 && (
        <ul className="divide-y divide-line">
          {query.data.data.slice(0, SHOWN).map((project) => {
            const count = project.image_count ?? 0
            return (
              <li key={project.id}>
                <Link
                  to={`/create/${project.id}`}
                  className="flex items-center gap-3 px-5 py-3.5 hover:bg-sunken"
                >
                  {project.cover_thumbnail_url ? (
                    <img
                      src={project.cover_thumbnail_url}
                      alt=""
                      className="size-10 shrink-0 rounded-lg bg-sunken object-cover"
                    />
                  ) : (
                    <span
                      aria-hidden="true"
                      className="grid size-10 shrink-0 place-items-center rounded-lg bg-sunken text-muted"
                    >
                      <ImageOff className="size-[18px]" />
                    </span>
                  )}
                  <div className="min-w-0 flex-1">
                    <p className="truncate text-sm font-medium">
                      {project.title || 'Untitled project'}
                    </p>
                    <p className="text-sm text-muted">
                      {count} {count === 1 ? 'image' : 'images'}
                      {project.updated_at ? ` · updated ${timeAgo(project.updated_at)}` : ''}
                    </p>
                  </div>
                  <Badge>{project.status === 'draft' ? 'Draft' : project.status}</Badge>
                </Link>
              </li>
            )
          })}
        </ul>
      )}
    </Card>
  )
}
