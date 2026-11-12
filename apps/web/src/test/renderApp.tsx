import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render } from '@testing-library/react'
import { createMemoryRouter, RouterProvider } from 'react-router-dom'
import { vi } from 'vitest'
import { routes } from '@/app/router'
import { AuthProvider } from '@/features/auth/AuthProvider'
import { ThemeProvider } from '@/shared/theme/ThemeProvider'
import { ToastProvider } from '@/shared/toast/ToastProvider'

export const jsonResponse = (body: unknown, status = 200) =>
  new Response(JSON.stringify(body), {
    status,
    headers: { 'Content-Type': 'application/json' },
  })

export const sampleUser = {
  id: '01JTESTUSER',
  name: 'Ada Lovelace',
  email: 'ada@example.com',
  email_verified: true,
  email_verified_at: '2026-11-12T10:00:00Z',
  roles: ['member'],
  permissions: ['projects.manage'],
  created_at: '2026-11-12T09:00:00Z',
  last_login_at: null,
}

export type Handler = (init: RequestInit | undefined) => Response | Promise<Response>

/** Replaces fetch with routes keyed by "METHOD /path" (the /api prefix is stripped). */
export function mockApi(handlers: Record<string, Handler>) {
  const calls: string[] = []
  vi.stubGlobal(
    'fetch',
    vi.fn(async (url: string, init?: RequestInit) => {
      const key = `${init?.method ?? 'GET'} ${url.replace(/^\/api/, '')}`
      calls.push(key)
      const handler = handlers[key]
      if (!handler) throw new Error(`Unexpected request: ${key}`)
      return handler(init)
    }),
  )
  return calls
}

export function renderApp(path: string) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  const router = createMemoryRouter(routes, { initialEntries: [path] })
  const view = render(
    <QueryClientProvider client={client}>
      <ThemeProvider>
        <ToastProvider>
          <AuthProvider>
            <RouterProvider router={router} />
          </AuthProvider>
        </ToastProvider>
      </ThemeProvider>
    </QueryClientProvider>,
  )
  return { ...view, router }
}

export const emptyActivity = () => jsonResponse({ data: [] })

export const emptyProjects = () =>
  jsonResponse({ data: [], meta: { current_page: 1, last_page: 1, total: 0 } })

/** Handlers every signed-in screen needs (who am I + the account activity list). */
export const signedInHandlers = (): Record<string, Handler> => ({
  'GET /v1/auth/me': () => jsonResponse({ data: sampleUser }),
  'GET /v1/account/activity': emptyActivity,
  'GET /v1/social/accounts': () => jsonResponse({ data: [] }),
  'GET /v1/projects?page=1': () => emptyProjects(),
})
