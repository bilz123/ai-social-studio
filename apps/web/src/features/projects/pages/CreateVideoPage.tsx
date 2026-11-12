import { Clapperboard, Plus } from 'lucide-react'
import { useState } from 'react'
import { PageHeader } from '@/shared/layout/PageHeader'
import { Button } from '@/shared/ui/Button'
import { Card } from '@/shared/ui/Card'
import { EmptyState } from '@/shared/ui/EmptyState'
import { ErrorState } from '@/shared/ui/ErrorState'
import { Skeleton } from '@/shared/ui/Skeleton'
import { ProjectCard } from '../components/ProjectCard'
import { useCreateProject, useProjectList } from '../useProjects'

/** Create Video home: your drafts, and a button that starts a new one. */
export function CreateVideoPage() {
  const [page, setPage] = useState(1)
  const projects = useProjectList(page)
  const create = useCreateProject()

  const start = () => create.mutate({ title: 'Untitled project' })

  const newButton = (
    <Button loading={create.isPending} onClick={start}>
      <Plus aria-hidden="true" className="size-4" />
      New project
    </Button>
  )

  return (
    <>
      <PageHeader
        title="Create Video"
        description="Start a project with product photos and a description. Your drafts are kept here until you are ready for the next step."
        actions={newButton}
      />

      {projects.isPending && (
        <ul
          role="status"
          aria-label="Loading projects"
          className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3"
        >
          {Array.from({ length: 3 }, (_, index) => (
            <li key={index}>
              <Skeleton className="h-60 rounded-xl" />
            </li>
          ))}
        </ul>
      )}

      {projects.isError && (
        <Card>
          <ErrorState
            title="Could not load your projects"
            onRetry={() => void projects.refetch()}
          />
        </Card>
      )}

      {projects.isSuccess && projects.data.data.length === 0 && (
        <Card>
          <EmptyState
            icon={Clapperboard}
            title="Start your first video"
            description="Create a project, add photos of your product and describe it. You can come back and change anything later."
            action={newButton}
          />
        </Card>
      )}

      {projects.isSuccess && projects.data.data.length > 0 && (
        <>
          <ul className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            {projects.data.data.map((project) => (
              <ProjectCard key={project.id} project={project} />
            ))}
          </ul>
          {projects.data.meta.last_page > 1 && (
            <nav
              aria-label="Project pages"
              className="mt-6 flex items-center justify-between gap-3"
            >
              <Button
                variant="secondary"
                size="sm"
                disabled={page <= 1}
                onClick={() => setPage((current) => current - 1)}
              >
                Newer
              </Button>
              <p className="text-sm text-muted">
                Page {projects.data.meta.current_page} of {projects.data.meta.last_page}
              </p>
              <Button
                variant="secondary"
                size="sm"
                disabled={page >= projects.data.meta.last_page}
                onClick={() => setPage((current) => current + 1)}
              >
                Older
              </Button>
            </nav>
          )}
        </>
      )}
    </>
  )
}
