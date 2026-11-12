import { Navigate, createBrowserRouter } from 'react-router-dom'
import { AuthLayout } from '@/features/auth/components/AuthLayout'
import { GuestOnly, RequireAuth } from '@/features/auth/components/Guards'
import { ForgotPasswordPage } from '@/features/auth/pages/ForgotPasswordPage'
import { LoginPage } from '@/features/auth/pages/LoginPage'
import { RegisterPage } from '@/features/auth/pages/RegisterPage'
import { ResetPasswordPage } from '@/features/auth/pages/ResetPasswordPage'
import { VerifyEmailPage } from '@/features/auth/pages/VerifyEmailPage'
import { DashboardPage } from '@/features/dashboard/pages/DashboardPage'
import { SettingsPage } from '@/features/settings/pages/SettingsPage'
import { ConnectedAccountsPage } from '@/features/social/pages/ConnectedAccountsPage'
import { ConnectionCallbackPage } from '@/features/social/pages/ConnectionCallbackPage'
import {
  AnalyticsPage,
  CreateVideoPage,
  MyVideosPage,
  PublishingHistoryPage,
  ScheduledPostsPage,
} from '@/features/placeholders/sectionPages'
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
          { path: '/create', element: <CreateVideoPage /> },
          { path: '/accounts', element: <ConnectedAccountsPage /> },
          { path: '/connections/callback/:platform', element: <ConnectionCallbackPage /> },
          { path: '/videos', element: <MyVideosPage /> },
          { path: '/history', element: <PublishingHistoryPage /> },
          { path: '/scheduled', element: <ScheduledPostsPage /> },
          { path: '/analytics', element: <AnalyticsPage /> },
          { path: '/settings', element: <SettingsPage /> },
          { path: '/profile', element: <Navigate to="/settings" replace /> },
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
