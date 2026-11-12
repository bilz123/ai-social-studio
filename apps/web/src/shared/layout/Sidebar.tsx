import { NavLink } from 'react-router-dom'
import { cn } from '@/shared/lib/cn'
import { Logo } from '@/shared/ui/Logo'
import { NAV_ITEMS } from './navItems'

const linkClass = ({ isActive }: { isActive: boolean }) =>
  cn(
    'group flex min-h-10 items-center gap-3 rounded-lg px-3 text-sm font-medium transition-colors',
    isActive ? 'bg-sunken text-ink' : 'text-muted hover:bg-sunken hover:text-ink',
  )

/** Primary navigation. Rendered once in the desktop rail and again inside the mobile drawer. */
export function Sidebar({ onNavigate }: { onNavigate?: () => void }) {
  const main = NAV_ITEMS.filter((item) => item.to !== '/settings')
  const settings = NAV_ITEMS.filter((item) => item.to === '/settings')

  return (
    <div className="flex h-full flex-col">
      <div className="flex h-16 shrink-0 items-center px-5">
        <Logo />
      </div>
      <nav aria-label="Main" className="flex flex-1 flex-col gap-1 overflow-y-auto px-3 pb-4">
        <ul className="flex flex-col gap-0.5">
          {main.map(({ to, label, icon: Icon }) => (
            <li key={to}>
              <NavLink to={to} className={linkClass} onClick={onNavigate}>
                <Icon aria-hidden="true" className="size-[18px] shrink-0" />
                {label}
              </NavLink>
            </li>
          ))}
        </ul>
        <ul className="mt-auto flex flex-col gap-0.5 border-t border-line pt-3">
          {settings.map(({ to, label, icon: Icon }) => (
            <li key={to}>
              <NavLink to={to} className={linkClass} onClick={onNavigate}>
                <Icon aria-hidden="true" className="size-[18px] shrink-0" />
                {label}
              </NavLink>
            </li>
          ))}
        </ul>
      </nav>
    </div>
  )
}
