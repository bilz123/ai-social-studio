import { FolderX } from 'lucide-react'
import { Link, useParams } from 'react-router-dom'
import { ApiError } from '@/shared/lib/apiClient'
import { Card } from '@/shared/ui/Card'
import { EmptyState } from '@/shared/ui/EmptyState'
import { ErrorState } from '@/shared/ui/ErrorState'
import { Skeleton } from '@/shared/ui/Skeleton'
import { buttonClasses } from '@/shared/ui/buttonStyles'
import { ProjectEditor } from '../components/ProjectEditor'
import { DEFAULT_LIMITS } from '../imageRules'
import { useProject } from '../useProjects'

export function ProjectEditorPage() {
  const { projectId = '' } = useParams()
  const query = useProject(projectId)

  if (query.isPending) {
    return (
      <div role="status" aria-label="Loading project" className="space-y-6">
        <Skeleton className="h-10 w-2/3" />
        <Skeleton className="h-28 rounded-xl" />
        <Skeleton className="h-72 rounded-xl" />
      </div>
    )
  }

  if (query.isError) {
    if (query.error instanceof ApiError && query.error.status === 404) {
      return (
        <Card>
          <EmptyState
            icon={FolderX}
            title="Project not found"
            description="It may have been deleted, or it belongs to a different account."
            action={
              <Link to="/create" className={buttonClasses('primary', 'sm')}>
                Back to your projects
              </Link>
            }
          />
        </Card>
      )
    }
    return (
      <Card>
        <ErrorState title="Could not load this project" onRetry={() => void query.refetch()} />
      </Card>
    )
  }

  return (
    // Keyed by id so moving between projects never carries text over from the previous one.
    <ProjectEditor
      key={query.data.data.id}
      project={query.data.data}
      limits={query.data.meta?.limits ?? DEFAULT_LIMITS}
    />
  )
}
