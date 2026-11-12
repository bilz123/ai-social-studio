import { Navigate, createBrowserRouter } from 'react-router-dom'
import { ProfilePage } from '@/features/account/pages/ProfilePage'
import { AuthLayout } from '@/features/auth/components/AuthLayout'
import { GuestOnly, RequireAuth } from '@/features/auth/components/Guards'
import { ForgotPasswordPage } from '@/features/auth/pages/ForgotPasswordPage'
import { LoginPage } from '@/features/auth/pages/LoginPage'
import { RegisterPage } from '@/features/auth/pages/RegisterPage'
import { ResetPasswordPage } from '@/features/auth/pages/ResetPasswordPage'
import { VerifyEmailPage } from '@/features/auth/pages/VerifyEmailPage'
import { DashboardPage } from '@/features/dashboard/pages/DashboardPage'
import { NotFoundPage } from '@/features/health/pages/NotFoundPage'
import { StatusPage } from '@/features/health/pages/StatusPage'
import { AppLayout } from '@/shared/layout/AppLayout'

export const routes = [
  { path: '/', element: <Navigate to="/dashboard" replace /> },
  { path: '/status', element: <StatusPage /> },
  {
    // Signed-out visitors only.
    element: <GuestOnly />,
    children: [
      {
        element: <AuthLayout />,
        children: [
          { path: '/login', element: <LoginPage /> },
          { path: '/register', element: <RegisterPage /> },
          { path: '/forgot-password', element: <ForgotPasswordPage /> },
        ],
      },
    ],
  },
  {
    // Reachable signed in or out: the links arrive by email.
    element: <AuthLayout />,
    children: [
      { path: '/reset-password', element: <ResetPasswordPage /> },
      { path: '/verify-email', element: <VerifyEmailPage /> },
    ],
  },
  {
    // Signed-in only.
    element: <RequireAuth />,
    children: [
      {
        element: <AppLayout />,
        children: [
          { path: '/dashboard', element: <DashboardPage /> },
          { path: '/profile', element: <ProfilePage /> },
        ],
      },
    ],
  },
  { path: '*', element: <NotFoundPage /> },
]

export function createRouter() {
  return createBrowserRouter(routes, {
    basename: import.meta.env.BASE_URL.replace(/\/$/, '') || undefined,
  })
}
