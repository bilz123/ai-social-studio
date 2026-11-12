import { Clapperboard } from 'lucide-react'
import { Link } from 'react-router-dom'
import { useAuth } from '@/features/auth/useAuth'
import { PageHeader } from '@/shared/layout/PageHeader'
import { buttonClasses } from '@/shared/ui/buttonStyles'
import { AccountActivity } from '../components/AccountActivity'
import { ConnectedAccounts } from '../components/ConnectedAccounts'
import { PublishingActivity } from '../components/PublishingActivity'
import { RecentProjects } from '../components/RecentProjects'
import { RecentVideos } from '../components/RecentVideos'
import { StatCards } from '../components/StatCards'

export function DashboardPage() {
  const { user } = useAuth()
  const firstName = user?.name.split(' ')[0] ?? ''

  return (
    <>
      <PageHeader
        title={`Hello, ${firstName}`}
        description="Here is where your videos and accounts stand."
        actions={
          <Link to="/create" className={buttonClasses('primary')}>
            <Clapperboard aria-hidden="true" className="size-4" />
            Create video
          </Link>
        }
      />
      <div className="space-y-6">
        <StatCards />
        <div className="grid items-start gap-6 lg:grid-cols-3">
          <RecentVideos />
          <ConnectedAccounts />
          <RecentProjects />
          <PublishingActivity />
        </div>
        <AccountActivity />
      </div>
    </>
  )
}
