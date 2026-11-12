import { Link, Outlet } from 'react-router-dom'
import { Logo } from '@/shared/ui/Logo'
import { ScreenFrame } from '@/shared/ui/ScreenFrame'

/** Split screen: the form on the left, the studio viewfinder on the right (hidden on phones). */
export function AuthLayout() {
  return (
    <div className="grid min-h-dvh lg:grid-cols-[minmax(0,1fr)_minmax(0,1.05fr)]">
      <div className="flex flex-col px-5 py-6 sm:px-10">
        <header>
          <Link to="/" aria-label="Home">
            <Logo />
          </Link>
        </header>
        <main className="mx-auto flex w-full max-w-md flex-1 flex-col justify-center py-10">
          <Outlet />
        </main>
      </div>
      <aside className="relative hidden items-center justify-center overflow-hidden bg-brand lg:flex">
        <div className="flex flex-col items-center gap-8 px-10">
          <ScreenFrame />
          <p className="max-w-xs text-center font-display text-xl font-medium leading-snug text-brand-contrast">
            Ten seconds is enough to stop the scroll.
          </p>
        </div>
      </aside>
    </div>
  )
}
