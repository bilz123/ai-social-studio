import type { LucideIcon } from 'lucide-react'
import { Link } from 'react-router-dom'
import { PageHeader } from '@/shared/layout/PageHeader'
import { Badge } from '@/shared/ui/Badge'
import { buttonClasses } from '@/shared/ui/buttonStyles'
import { Card } from '@/shared/ui/Card'
import { EmptyState } from '@/shared/ui/EmptyState'

/**
 * PLACEHOLDER screen for a sidebar section whose feature is not built yet. It keeps the
 * navigation complete and tells the person what the section will do.
 */
export function SectionPlaceholderPage({
  title,
  description,
  icon,
  emptyTitle,
  emptyDescription,
}: {
  title: string
  description: string
  icon: LucideIcon
  emptyTitle: string
  emptyDescription: string
}) {
  return (
    <>
      <PageHeader
        title={title}
        description={description}
        actions={<Badge tone="warn">Coming soon</Badge>}
      />
      <Card>
        <EmptyState
          icon={icon}
          title={emptyTitle}
          description={emptyDescription}
          action={
            <Link to="/dashboard" className={buttonClasses('secondary', 'sm')}>
              Back to dashboard
            </Link>
          }
        />
      </Card>
    </>
  )
}
