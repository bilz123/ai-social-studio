import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import type { SocialAccount, SocialPlatform } from '@/features/social/api/social'
import { redirectTo } from '@/shared/lib/navigation'
import { jsonResponse, mockApi, renderApp, signedInHandlers } from '@/test/renderApp'

vi.mock('@/shared/lib/navigation', () => ({ redirectTo: vi.fn() }))

beforeEach(() => {
  vi.mocked(redirectTo).mockClear()
  // Present, so the API client does not first fetch a CSRF cookie before state-changing requests.
  document.cookie = 'XSRF-TOKEN=token'
})
afterEach(() => {
  vi.unstubAllGlobals()
  document.cookie = 'XSRF-TOKEN=; expires=Thu, 01 Jan 1970 00:00:00 GMT'
})

const platforms = (overrides: Partial<Record<string, Partial<SocialPlatform>>> = {}) => ({
  data: (
    [
      ['facebook', 'Facebook', 'meta'],
      ['instagram', 'Instagram', 'instagram'],
      ['youtube', 'YouTube', 'google'],
    ] as const
  ).map(([id, name, provider]) => ({
    id,
    name,
    provider,
    configured: true,
    supports_refresh: id !== 'facebook',
    required_permissions: [],
    connected_count: 0,
    ...overrides[id],
  })),
})

const account = (overrides: Partial<SocialAccount> = {}): SocialAccount => ({
  id: '01ACCOUNT1',
  platform: 'facebook',
  provider: 'meta',
  name: 'Acme Coffee',
  username: 'acmecoffee',
  avatar_url: null,
  status: 'active',
  status_reason: null,
  is_enabled: true,
  capabilities: {},
  profile: { category: 'Cafe', followers: 1280 },
  permissions: { granted: [], missing: [] },
  token: { present: true, expires_at: null, has_refresh_token: false, last_error: null },
  connected_at: '2026-11-12T10:00:00Z',
  last_checked_at: null,
  last_publish_at: null,
  ...overrides,
})

const socialHandlers = (accounts: SocialAccount[] = [], platformData = platforms()) => ({
  ...signedInHandlers(),
  'GET /v1/social/platforms': () => jsonResponse(platformData),
  'GET /v1/social/accounts': () => jsonResponse({ data: accounts }),
})

describe('connected accounts page', () => {
  it('shows skeletons, then the platforms and an empty state', async () => {
    mockApi(socialHandlers())
    renderApp('/accounts')

    expect(await screen.findByRole('status', { name: 'Loading platforms' })).toBeInTheDocument()
    expect(await screen.findByRole('button', { name: 'Connect Facebook' })).toBeEnabled()
    expect(screen.getByRole('button', { name: 'Connect Instagram' })).toBeEnabled()
    expect(screen.getByRole('button', { name: 'Connect YouTube' })).toBeEnabled()
    expect(await screen.findByText('No accounts connected')).toBeInTheDocument()
  })

  it('explains a platform that is not set up instead of faking a connection', async () => {
    mockApi(
      socialHandlers(
        [],
        platforms({
          instagram: {
            configured: false,
            missing_configuration: ['INSTAGRAM_APP_ID', 'INSTAGRAM_APP_SECRET'],
          },
        }),
      ),
    )
    renderApp('/accounts')

    const button = await screen.findByRole('button', { name: 'Connect Instagram' })
    expect(button).toBeDisabled()
    expect(button).toHaveAccessibleDescription(/INSTAGRAM_APP_ID, INSTAGRAM_APP_SECRET/)
    expect(screen.getByText('Not set up')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Connect Facebook' })).toBeEnabled()
  })

  it('sends the browser to the provider consent screen', async () => {
    const calls = mockApi({
      ...socialHandlers(),
      'POST /v1/social/facebook/connect': () =>
        jsonResponse({
          data: {
            authorization_url: 'https://www.facebook.com/v26.0/dialog/oauth?state=abc',
            expires_at: '2026-11-12T10:10:00Z',
          },
        }),
    })
    const user = userEvent.setup()
    renderApp('/accounts')

    await user.click(await screen.findByRole('button', { name: 'Connect Facebook' }))

    await waitFor(() =>
      expect(redirectTo).toHaveBeenCalledWith(
        'https://www.facebook.com/v26.0/dialog/oauth?state=abc',
      ),
    )
    expect(calls).toContain('POST /v1/social/facebook/connect')
  })

  it('tells the person when the connection cannot be started', async () => {
    mockApi({
      ...socialHandlers(),
      'POST /v1/social/youtube/connect': () =>
        jsonResponse(
          {
            code: 'integration_not_configured',
            detail: 'YouTube publishing is not set up on this server yet.',
          },
          503,
        ),
    })
    const user = userEvent.setup()
    renderApp('/accounts')

    await user.click(await screen.findByRole('button', { name: 'Connect YouTube' }))

    expect(await screen.findByText('Could not start the connection')).toBeInTheDocument()
    expect(
      screen.getByText('YouTube publishing is not set up on this server yet.'),
    ).toBeInTheDocument()
    expect(redirectTo).not.toHaveBeenCalled()
  })

  it('lists connected accounts with their details and token status', async () => {
    mockApi(
      socialHandlers(
        [
          account(),
          account({
            id: '01ACCOUNT2',
            platform: 'youtube',
            name: 'Acme Channel',
            username: '@acme',
            profile: { subscribers: 2300, videos: 41 },
            token: {
              present: true,
              expires_at: '2026-11-12T11:00:00Z',
              has_refresh_token: true,
              last_error: null,
            },
          }),
        ],
        platforms({ facebook: { connected_count: 1 }, youtube: { connected_count: 1 } }),
      ),
    )
    renderApp('/accounts')

    const facebook = await screen.findByRole('listitem', { name: 'Facebook account Acme Coffee' })
    expect(within(facebook).getByText('Connected')).toBeInTheDocument()
    expect(within(facebook).getByText(/Cafe · 1,280 followers/)).toBeInTheDocument()
    expect(within(facebook).getByText(/Access does not expire/)).toBeInTheDocument()

    const youtube = screen.getByRole('listitem', { name: 'YouTube account Acme Channel' })
    expect(within(youtube).getByText(/2,300 subscribers · 41 videos/)).toBeInTheDocument()
    expect(within(youtube).getByText(/Access renews automatically/)).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Add another Facebook account' })).toBeInTheDocument()
  })

  it('offers to reconnect an account that needs it and explains why', async () => {
    mockApi({
      ...socialHandlers([
        account({
          status: 'needs_reconnect',
          status_reason: 'Facebook no longer accepts this connection. Reconnect the account.',
        }),
      ]),
      'POST /v1/social/facebook/connect': () =>
        jsonResponse({
          data: {
            authorization_url: 'https://www.facebook.com/dialog',
            expires_at: '2026-11-12T10:10:00Z',
          },
        }),
    })
    const user = userEvent.setup()
    renderApp('/accounts')

    const card = await screen.findByRole('listitem', { name: 'Facebook account Acme Coffee' })
    expect(within(card).getByText('Needs reconnect')).toBeInTheDocument()
    expect(within(card).getByText(/no longer accepts this connection/)).toBeInTheDocument()
    expect(within(card).queryByRole('switch')).not.toBeInTheDocument()

    await user.click(within(card).getByRole('button', { name: 'Reconnect' }))

    await waitFor(() => expect(redirectTo).toHaveBeenCalledWith('https://www.facebook.com/dialog'))
  })

  it('shows missing permissions for an account with limited access', async () => {
    mockApi(
      socialHandlers([
        account({
          status: 'restricted',
          status_reason: 'Missing permissions: pages_manage_posts.',
          permissions: { granted: ['pages_show_list'], missing: ['pages_manage_posts'] },
        }),
      ]),
    )
    renderApp('/accounts')

    const card = await screen.findByRole('listitem', { name: 'Facebook account Acme Coffee' })
    expect(within(card).getByText('Limited access')).toBeInTheDocument()
    expect(within(card).getByText(/Missing permissions: pages_manage_posts$/)).toBeInTheDocument()
    expect(within(card).getByRole('button', { name: 'Reconnect' })).toBeInTheDocument()
  })

  it('checks a connection and refreshes the list', async () => {
    let checked = false
    const calls = mockApi({
      ...socialHandlers(),
      'GET /v1/social/accounts': () =>
        jsonResponse({
          data: [account({ name: checked ? 'Acme Coffee Co' : 'Acme Coffee' })],
        }),
      'POST /v1/social/accounts/01ACCOUNT1/check': () => {
        checked = true
        return jsonResponse({ data: account({ name: 'Acme Coffee Co' }) })
      },
    })
    const user = userEvent.setup()
    renderApp('/accounts')

    const card = await screen.findByRole('listitem', { name: 'Facebook account Acme Coffee' })
    await user.click(within(card).getByRole('button', { name: 'Check connection' }))

    expect(await screen.findByText('Acme Coffee Co is connected')).toBeInTheDocument()
    expect(
      await screen.findByRole('listitem', { name: 'Facebook account Acme Coffee Co' }),
    ).toBeInTheDocument()
    expect(calls).toContain('POST /v1/social/accounts/01ACCOUNT1/check')
  })

  it('reports a failed check and shows the new status', async () => {
    let failed = false
    mockApi({
      ...socialHandlers(),
      'GET /v1/social/accounts': () =>
        jsonResponse({
          data: [
            account(
              failed
                ? {
                    status: 'needs_reconnect',
                    status_reason: 'Facebook no longer accepts this connection.',
                  }
                : {},
            ),
          ],
        }),
      'POST /v1/social/accounts/01ACCOUNT1/check': () => {
        failed = true
        return jsonResponse(
          {
            code: 'token_rejected',
            detail: 'Facebook no longer accepts this connection. Reconnect the account.',
          },
          409,
        )
      },
    })
    const user = userEvent.setup()
    renderApp('/accounts')

    const card = await screen.findByRole('listitem', { name: 'Facebook account Acme Coffee' })
    await user.click(within(card).getByRole('button', { name: 'Check connection' }))

    expect(await screen.findByText('Connection check failed')).toBeInTheDocument()
    expect(await within(card).findByText('Needs reconnect')).toBeInTheDocument()
  })

  it('pauses publishing to an account', async () => {
    let body: unknown
    mockApi({
      ...socialHandlers([account()]),
      'PATCH /v1/social/accounts/01ACCOUNT1': (init) => {
        body = JSON.parse(String(init?.body))
        return jsonResponse({ data: account({ is_enabled: false }) })
      },
    })
    const user = userEvent.setup()
    renderApp('/accounts')

    const card = await screen.findByRole('listitem', { name: 'Facebook account Acme Coffee' })
    await user.click(within(card).getByRole('switch', { name: /Publishing/ }))

    await waitFor(() => expect(body).toEqual({ is_enabled: false }))
    expect(await screen.findByText('Publishing to Acme Coffee is paused')).toBeInTheDocument()
  })

  it('asks for confirmation before disconnecting, then removes the account', async () => {
    let disconnected = false
    const calls = mockApi({
      ...socialHandlers(),
      'GET /v1/social/accounts': () => jsonResponse({ data: disconnected ? [] : [account()] }),
      'DELETE /v1/social/accounts/01ACCOUNT1': () => {
        disconnected = true
        return new Response(null, { status: 204 })
      },
    })
    const user = userEvent.setup()
    renderApp('/accounts')

    const card = await screen.findByRole('listitem', { name: 'Facebook account Acme Coffee' })
    await user.click(within(card).getByRole('button', { name: 'Disconnect' }))
    expect(calls).not.toContain('DELETE /v1/social/accounts/01ACCOUNT1')

    // Cancel keeps the account.
    const confirm = within(card).getByRole('group', { name: 'Confirm disconnecting Acme Coffee' })
    await user.click(within(confirm).getByRole('button', { name: 'Cancel' }))
    expect(within(card).queryByRole('group')).not.toBeInTheDocument()

    await user.click(within(card).getByRole('button', { name: 'Disconnect' }))
    await user.click(
      within(within(card).getByRole('group')).getByRole('button', { name: 'Disconnect' }),
    )

    expect(await screen.findByText('Disconnected Acme Coffee')).toBeInTheDocument()
    expect(await screen.findByText('No accounts connected')).toBeInTheDocument()
    expect(calls).toContain('DELETE /v1/social/accounts/01ACCOUNT1')
  })

  it('shows an error state with a working retry', async () => {
    let attempts = 0
    mockApi({
      ...socialHandlers(),
      'GET /v1/social/accounts': () => {
        attempts += 1
        return attempts === 1
          ? jsonResponse({ code: 'internal_error' }, 500)
          : jsonResponse({ data: [account()] })
      },
    })
    const user = userEvent.setup()
    renderApp('/accounts')

    expect(await screen.findByText('Could not load your accounts')).toBeInTheDocument()
    await user.click(screen.getAllByRole('button', { name: 'Try again' })[0] as HTMLElement)
    expect(
      await screen.findByRole('listitem', { name: 'Facebook account Acme Coffee' }),
    ).toBeInTheDocument()
  })
})

describe('connection callback', () => {
  it('forwards the code and state once and shows the connected accounts', async () => {
    let body: unknown
    const calls = mockApi({
      ...socialHandlers(),
      'POST /v1/social/facebook/callback': (init) => {
        body = JSON.parse(String(init?.body))
        return jsonResponse({
          data: [account(), account({ id: '01ACCOUNT3', name: 'Acme Roasters' })],
        })
      },
    })
    const { router } = renderApp('/connections/callback/facebook?code=the-code&state=the-state')

    expect(await screen.findByText('Facebook is connected')).toBeInTheDocument()
    expect(screen.getByText('Acme Coffee, Acme Roasters')).toBeInTheDocument()
    expect(await screen.findByText('2 accounts added.')).toBeInTheDocument()
    expect(body).toEqual({ state: 'the-state', code: 'the-code' })
    expect(
      calls.filter((call) => call.startsWith('POST /v1/social/facebook/callback')),
    ).toHaveLength(1)
    // The one-time code is removed from the address bar.
    await waitFor(() => expect(router.state.location.search).toBe(''))
    expect(screen.getByRole('link', { name: 'Back to connected accounts' })).toHaveAttribute(
      'href',
      '/accounts',
    )
  })

  it('tells the person when they did not grant access', async () => {
    let body: unknown
    mockApi({
      ...socialHandlers(),
      'POST /v1/social/instagram/callback': (init) => {
        body = JSON.parse(String(init?.body))
        return jsonResponse(
          {
            code: 'authorization_denied',
            detail:
              'Instagram did not grant access. Connect again and approve the requested permissions.',
          },
          400,
        )
      },
    })
    renderApp('/connections/callback/instagram?error=access_denied&state=s1')

    expect(await screen.findByText('Access was not granted')).toBeInTheDocument()
    expect(
      screen.getByText(/Connect again and approve the requested permissions/),
    ).toBeInTheDocument()
    expect(body).toEqual({ state: 's1', error: 'access_denied' })
  })

  it('explains an expired or reused connection attempt', async () => {
    mockApi({
      ...socialHandlers(),
      'POST /v1/social/youtube/callback': () =>
        jsonResponse(
          {
            code: 'invalid_oauth_state',
            detail:
              'This connection attempt has expired or was not started from this account. Start the connection again.',
          },
          400,
        ),
    })
    renderApp('/connections/callback/youtube?code=c&state=old')

    expect(await screen.findByText('This connection attempt expired')).toBeInTheDocument()
  })

  it('explains an account that cannot be used', async () => {
    mockApi({
      ...socialHandlers(),
      'POST /v1/social/instagram/callback': () =>
        jsonResponse(
          {
            code: 'account_not_eligible',
            detail:
              'This Instagram account is a personal account. Switch it to a Business or Creator account in Instagram, then connect again.',
          },
          422,
        ),
    })
    renderApp('/connections/callback/instagram?code=c&state=s')

    expect(await screen.findByText('This account cannot be connected')).toBeInTheDocument()
    expect(screen.getByText(/Business or Creator account/)).toBeInTheDocument()
  })

  it('does not call the API when the page is opened without a connection in progress', async () => {
    const calls = mockApi(socialHandlers())
    renderApp('/connections/callback/facebook')

    expect(await screen.findByText('No connection in progress')).toBeInTheDocument()
    expect(calls.some((call) => call.includes('/callback'))).toBe(false)
  })

  it('sends unknown platforms back to the accounts page', async () => {
    mockApi(socialHandlers())
    const { router } = renderApp('/connections/callback/tiktok?code=c&state=s')

    await waitFor(() => expect(router.state.location.pathname).toBe('/accounts'))
  })
})
