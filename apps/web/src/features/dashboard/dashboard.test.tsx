import { screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { jsonResponse, mockApi, renderApp, signedInHandlers } from '@/test/renderApp'

afterEach(() => vi.unstubAllGlobals())

describe('dashboard', () => {
  it('shows loading skeletons first, then every sample widget labelled as sample data', async () => {
    mockApi(signedInHandlers())
    renderApp('/dashboard')

    expect(await screen.findByRole('status', { name: 'Loading recent videos' })).toBeInTheDocument()

    const videos = await screen.findByRole('region', { name: 'Recent videos' })
    expect(await within(videos).findByText('Pour-over set, slow orbit')).toBeInTheDocument()
    expect(within(videos).getByText('Awaiting approval')).toBeInTheDocument()

    for (const name of ['Recent projects', 'Connected accounts', 'Publishing activity']) {
      const card = await screen.findByRole('region', { name })
      expect(within(card).getByText('Sample data')).toBeInTheDocument()
    }
    expect(screen.getByRole('region', { name: 'Overview' })).toBeInTheDocument()
    expect(await screen.findByText('Videos generated')).toBeInTheDocument()
  })

  it('shows real account activity from the API', async () => {
    mockApi({
      ...signedInHandlers(),
      'GET /v1/account/activity': () =>
        jsonResponse({
          data: [
            {
              action: 'auth.login',
              ip: '10.0.0.1',
              user_agent: 'x',
              created_at: '2026-11-12T10:00:00Z',
            },
            {
              action: 'account.password_changed',
              ip: null,
              user_agent: 'x',
              created_at: '2026-11-12T09:00:00Z',
            },
          ],
        }),
    })
    renderApp('/dashboard')

    const card = await screen.findByRole('region', { name: 'Account activity' })
    expect(await within(card).findByText('Signed in')).toBeInTheDocument()
    expect(within(card).getByText('Password changed')).toBeInTheDocument()
    expect(within(card).getByText('Live')).toBeInTheDocument()
  })

  it('shows an empty state when there is no account activity', async () => {
    mockApi(signedInHandlers())
    renderApp('/dashboard')

    expect(await screen.findByText('No activity yet')).toBeInTheDocument()
  })

  it('shows an error state with a working retry when account activity fails', async () => {
    let attempts = 0
    mockApi({
      ...signedInHandlers(),
      'GET /v1/account/activity': () => {
        attempts += 1
        return attempts === 1
          ? jsonResponse({ code: 'internal_error', detail: 'Boom.' }, 500)
          : jsonResponse({
              data: [
                {
                  action: 'auth.login',
                  ip: null,
                  user_agent: 'x',
                  created_at: '2026-11-12T10:00:00Z',
                },
              ],
            })
      },
    })
    const user = userEvent.setup()
    renderApp('/dashboard')

    const card = await screen.findByRole('region', { name: 'Account activity' })
    expect(await within(card).findByText('Could not load your activity')).toBeInTheDocument()
    await user.click(within(card).getByRole('button', { name: 'Try again' }))
    expect(await within(card).findByText('Signed in')).toBeInTheDocument()
  })

  it('links the primary action to video creation', async () => {
    mockApi(signedInHandlers())
    renderApp('/dashboard')

    const link = await screen.findByRole('link', { name: 'Create video' })
    expect(link).toHaveAttribute('href', '/create')
  })
})
