import { createBrowserRouter } from 'react-router-dom'
import { NotFoundPage } from '@/features/health/pages/NotFoundPage'
import { StatusPage } from '@/features/health/pages/StatusPage'

export function createRouter() {
  return createBrowserRouter(
    [
      { path: '/', element: <StatusPage /> },
      { path: '*', element: <NotFoundPage /> },
    ],
    { basename: import.meta.env.BASE_URL.replace(/\/$/, '') || undefined },
  )
}
