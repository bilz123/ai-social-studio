import { screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { jsonResponse, mockApi, renderApp, signedInHandlers } from '@/test/renderApp'

afterEach(() => vi.unstubAllGlobals())

describe('dashboard', () => {
  it('shows loading skeletons first, then the sample widgets labelled as sample data', async () => {
    mockApi(signedInHandlers())
    renderApp('/dashboard')

    expect(await screen.findByRole('status', { name: 'Loading recent videos' })).toBeInTheDocument()

    const videos = await screen.findByRole('region', { name: 'Recent videos' })
    expect(await within(videos).findByText('Pour-over set, slow orbit')).toBeInTheDocument()
    expect(within(videos).getByText('Awaiting approval')).toBeInTheDocument()

    const publishing = await screen.findByRole('region', { name: 'Publishing activity' })
    expect(within(publishing).getByText('Sample data')).toBeInTheDocument()
    expect(screen.getByRole('region', { name: 'Overview' })).toBeInTheDocument()
    expect(await screen.findByText('Videos generated')).toBeInTheDocument()
  })

  it('shows the real projects, with an empty state when there are none', async () => {
    mockApi(signedInHandlers())
    renderApp('/dashboard')

    const empty = await screen.findByRole('region', { name: 'Recent projects' })
    expect(await within(empty).findByText('No projects yet')).toBeInTheDocument()
    expect(within(empty).queryByText('Sample data')).not.toBeInTheDocument()
  })

  it('lists recent projects and counts them', async () => {
    mockApi({
      ...signedInHandlers(),
      'GET /v1/projects?page=1': () =>
        jsonResponse({
          data: [
            {
              id: '01PROJECTAAAAAAAAAAAAAAAAA',
              title: 'Ceramic mug launch',
              product_name: 'Mug',
              product_description: '',
              status: 'draft',
              image_count: 3,
              cover_thumbnail_url: null,
              created_at: '2026-11-12T09:00:00Z',
              updated_at: '2026-11-12T10:00:00Z',
            },
          ],
          meta: { current_page: 1, last_page: 1, total: 1 },
        }),
    })
    renderApp('/dashboard')

    const card = await screen.findByRole('region', { name: 'Recent projects' })
    const link = await within(card).findByRole('link', { name: /Ceramic mug launch/ })
    expect(link).toHaveAttribute('href', '/create/01PROJECTAAAAAAAAAAAAAAAAA')
    expect(within(card).getByText(/3 images/)).toBeInTheDocument()
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

  it('shows the real connected accounts, or a prompt to connect one', async () => {
    mockApi({
      ...signedInHandlers(),
      'GET /v1/social/accounts': () =>
        jsonResponse({
          data: [
            {
              id: '01ACC1',
              platform: 'youtube',
              provider: 'google',
              name: 'Acme Coffee',
              username: '@acmecoffee',
              avatar_url: null,
              status: 'active',
              status_reason: null,
              is_enabled: true,
              capabilities: {},
              profile: {},
              permissions: { granted: [], missing: [] },
              token: { present: true, expires_at: null, has_refresh_token: true, last_error: null },
              connected_at: null,
              last_checked_at: null,
              last_publish_at: null,
            },
            {
              id: '01ACC2',
              platform: 'instagram',
              provider: 'instagram',
              name: 'Acme Reels',
              username: 'acme.reels',
              avatar_url: null,
              status: 'needs_reconnect',
              status_reason: 'Instagram no longer accepts this connection. Reconnect the account.',
              is_enabled: true,
              capabilities: {},
              profile: {},
              permissions: { granted: [], missing: [] },
              token: {
                present: true,
                expires_at: null,
                has_refresh_token: false,
                last_error: null,
              },
              connected_at: null,
              last_checked_at: null,
              last_publish_at: null,
            },
          ],
        }),
    })
    renderApp('/dashboard')

    const card = await screen.findByRole('region', { name: 'Connected accounts' })
    expect(await within(card).findByText('Acme Coffee')).toBeInTheDocument()
    expect(within(card).getByText('Connected')).toBeInTheDocument()
    expect(within(card).getByText('Needs reconnect')).toBeInTheDocument()
    expect(within(card).queryByText('Sample data')).not.toBeInTheDocument()
    expect(await screen.findByText('1 needs attention')).toBeInTheDocument()
  })

  it('invites the person to connect an account when there are none', async () => {
    mockApi(signedInHandlers())
    renderApp('/dashboard')

    const card = await screen.findByRole('region', { name: 'Connected accounts' })
    expect(await within(card).findByText('No accounts connected')).toBeInTheDocument()
    expect(within(card).getByRole('link', { name: 'Connect an account' })).toHaveAttribute(
      'href',
      '/accounts',
    )
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
