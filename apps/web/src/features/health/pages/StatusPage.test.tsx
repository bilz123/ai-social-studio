import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { StatusPage } from './StatusPage'

function renderPage() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={client}>
      <MemoryRouter>
        <StatusPage />
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

function mockApi(routes: Record<string, Response>) {
  vi.stubGlobal(
    'fetch',
    vi.fn(async (url: string) => {
      const response = routes[url]
      if (!response) throw new Error(`Unexpected request: ${url}`)
      return response.clone()
    }),
  )
}

const json = (body: unknown, status = 200) =>
  new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } })

afterEach(() => {
  vi.unstubAllGlobals()
})

describe('StatusPage', () => {
  it('shows operational status when the API and its dependencies are healthy', async () => {
    mockApi({
      '/api/v1/health': json({ status: 'ok', service: 'api', api_version: 'v1' }),
      '/api/v1/health/ready': json({
        status: 'ok',
        checks: { database: { status: 'ok' }, redis: { status: 'ok' } },
      }),
    })

    renderPage()

    expect(await screen.findAllByText('Operational')).toHaveLength(2)
    expect(screen.getByText('database')).toBeInTheDocument()
    expect(screen.getByText('redis')).toBeInTheDocument()
  })

  it('flags a degraded dependency without leaking details', async () => {
    mockApi({
      '/api/v1/health': json({ status: 'ok', service: 'api', api_version: 'v1' }),
      '/api/v1/health/ready': json(
        { status: 'degraded', checks: { database: { status: 'ok' }, redis: { status: 'fail' } } },
        503,
      ),
    })

    renderPage()

    expect(await screen.findByText('Down')).toBeInTheDocument()
    expect(screen.getByText('unavailable')).toBeInTheDocument()
  })

  it('shows an error with the request id when the API is unreachable', async () => {
    mockApi({
      '/api/v1/health': json({ code: 'internal_error', detail: 'Boom.', request_id: 'req-9' }, 500),
      '/api/v1/health/ready': json({ code: 'internal_error', request_id: 'req-9' }, 500),
    })

    renderPage()

    const alerts = await screen.findAllByRole('alert')
    expect(alerts[0]).toHaveTextContent('Boom. (request req-9)')
  })
})
