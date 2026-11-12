import { Navigate, Outlet, useLocation } from 'react-router-dom'
import { useAuth } from '../useAuth'

function Splash() {
  return (
    <div role="status" className="grid min-h-dvh place-items-center text-muted">
      Loading…
    </div>
  )
}

/** Only for signed-in people. Everyone else is sent to sign in and returned afterwards. */
export function RequireAuth() {
  const { user, loading } = useAuth()
  const location = useLocation()

  if (loading) return <Splash />
  if (!user) return <Navigate to="/login" replace state={{ from: location.pathname }} />
  return <Outlet />
}

/** Only for signed-out visitors (sign in, register…). Signed-in people go to the dashboard. */
export function GuestOnly() {
  const { user, loading } = useAuth()

  if (loading) return <Splash />
  if (user) return <Navigate to="/dashboard" replace />
  return <Outlet />
}
