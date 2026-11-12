import {
  BarChart3,
  CalendarClock,
  Clapperboard,
  Film,
  History,
  LayoutDashboard,
  Link2,
  Settings,
} from 'lucide-react'
import type { LucideIcon } from 'lucide-react'

export interface NavItem {
  to: string
  label: string
  icon: LucideIcon
}

export const NAV_ITEMS: NavItem[] = [
  { to: '/dashboard', label: 'Dashboard', icon: LayoutDashboard },
  { to: '/create', label: 'Create Video', icon: Clapperboard },
  { to: '/accounts', label: 'Connected Accounts', icon: Link2 },
  { to: '/videos', label: 'My Videos', icon: Film },
  { to: '/history', label: 'Publishing History', icon: History },
  { to: '/scheduled', label: 'Scheduled Posts', icon: CalendarClock },
  { to: '/analytics', label: 'Analytics', icon: BarChart3 },
  { to: '/settings', label: 'Settings', icon: Settings },
]
