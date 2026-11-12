import { BarChart3, CalendarClock, Film, History } from 'lucide-react'
import { SectionPlaceholderPage } from './SectionPlaceholderPage'

export const MyVideosPage = () => (
  <SectionPlaceholderPage
    title="My Videos"
    description="Every video you have generated, with its approval status."
    icon={Film}
    emptyTitle="No videos to show yet"
    emptyDescription="Generated videos will appear here for preview, approval and download."
  />
)

export const PublishingHistoryPage = () => (
  <SectionPlaceholderPage
    title="Publishing History"
    description="Where each video was posted and whether it succeeded."
    icon={History}
    emptyTitle="Nothing published yet"
    emptyDescription="Your posts to Facebook, Instagram and YouTube will be listed here."
  />
)

export const ScheduledPostsPage = () => (
  <SectionPlaceholderPage
    title="Scheduled Posts"
    description="Posts waiting for their publish time."
    icon={CalendarClock}
    emptyTitle="No scheduled posts"
    emptyDescription="Schedule an approved video and it will be queued here until it goes out."
  />
)

export const AnalyticsPage = () => (
  <SectionPlaceholderPage
    title="Analytics"
    description="How your published videos perform."
    icon={BarChart3}
    emptyTitle="Analytics are not available yet"
    emptyDescription="Views, reach and engagement per platform will be charted here."
  />
)
