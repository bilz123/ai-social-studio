import { screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { jsonResponse, mockApi, renderApp, sampleUser, signedInHandlers } from '@/test/renderApp'

afterEach(() => {
  vi.unstubAllGlobals()
  document.cookie = 'XSRF-TOKEN=; expires=Thu, 01 Jan 1970 00:00:00 GMT'
})

describe('settings', () => {
  it('moves between sections with the arrow keys', async () => {
    mockApi(signedInHandlers())
    const user = userEvent.setup()
    renderApp('/settings')

    const profile = await screen.findByRole('tab', { name: 'Profile' })
    expect(profile).toHaveAttribute('aria-selected', 'true')
    profile.focus()
    await user.keyboard('{ArrowRight}')

    const security = screen.getByRole('tab', { name: 'Security' })
    expect(security).toHaveAttribute('aria-selected', 'true')
    expect(security).toHaveFocus()
    expect(screen.getByRole('tabpanel')).toHaveAccessibleName('Security')
    expect(screen.getByLabelText('Current password')).toBeInTheDocument()
  })

  it('opens the right section from the address and sends /profile to settings', async () => {
    mockApi(signedInHandlers())
    const { router } = renderApp('/profile')

    await screen.findByRole('heading', { level: 1, name: 'Settings' })
    expect(router.state.location.pathname).toBe('/settings')
  })

  it('saves the profile and confirms with a toast', async () => {
    document.cookie = 'XSRF-TOKEN=token'
    mockApi({
      ...signedInHandlers(),
      'PATCH /v1/account/profile': (init) => {
        expect(JSON.parse(String(init?.body))).toEqual({
          name: 'Ada King',
          email: 'ada@example.com',
        })
        return jsonResponse({ data: { ...sampleUser, name: 'Ada King' } })
      },
    })
    const user = userEvent.setup()
    renderApp('/settings')

    const name = await screen.findByLabelText('Full name')
    await user.clear(name)
    await user.type(name, 'Ada King')
    await user.click(screen.getByRole('button', { name: 'Save changes' }))

    expect(await screen.findByText('Profile saved')).toBeInTheDocument()
  })

  it('shows field errors from the server', async () => {
    document.cookie = 'XSRF-TOKEN=token'
    mockApi({
      ...signedInHandlers(),
      'PUT /v1/account/password': () =>
        jsonResponse(
          {
            code: 'validation_failed',
            errors: { current_password: ['The password is incorrect.'] },
          },
          422,
        ),
    })
    const user = userEvent.setup()
    renderApp('/settings?tab=security')

    await user.type(await screen.findByLabelText('Current password'), 'wrong')
    await user.type(screen.getByLabelText('New password'), 'Str0ng!Passphrase#2')
    await user.type(screen.getByLabelText('Confirm new password'), 'Str0ng!Passphrase#2')
    await user.click(screen.getByRole('button', { name: 'Change password' }))

    expect(await screen.findByText('The password is incorrect.')).toBeInTheDocument()
  })

  it('blocks a password change while the confirmation differs', async () => {
    const calls = mockApi(signedInHandlers())
    const user = userEvent.setup()
    renderApp('/settings?tab=security')

    await user.type(await screen.findByLabelText('Current password'), 'old')
    await user.type(screen.getByLabelText('New password'), 'Str0ng!Passphrase#2')
    await user.type(screen.getByLabelText('Confirm new password'), 'nope')
    await user.click(screen.getByRole('button', { name: 'Change password' }))

    expect(screen.getByText('The passwords do not match.')).toBeInTheDocument()
    expect(calls).not.toContain('PUT /v1/account/password')
  })

  it('requires a password to delete the account, then signs out', async () => {
    document.cookie = 'XSRF-TOKEN=token'
    const calls = mockApi({
      ...signedInHandlers(),
      'DELETE /v1/account': () => new Response(null, { status: 204 }),
      'POST /v1/auth/logout': () => jsonResponse({ code: 'unauthenticated' }, 401),
    })
    const user = userEvent.setup()
    const { router } = renderApp('/settings')

    await user.click(await screen.findByRole('button', { name: 'Delete my account' }))
    await user.type(screen.getByLabelText('Confirm with your password'), 'Str0ng!Passphrase#1')
    await user.click(screen.getByRole('button', { name: 'Delete permanently' }))

    expect(await screen.findByText('Account deleted')).toBeInTheDocument()
    expect(calls).toContain('DELETE /v1/account')
    expect(router.state.location.pathname).toBe('/login')
  })

  it('marks notification preferences as a preview and toggles them', async () => {
    mockApi(signedInHandlers())
    const user = userEvent.setup()
    renderApp('/settings?tab=notifications')

    const panel = await screen.findByRole('tabpanel')
    expect(within(panel).getByText('Preview')).toBeInTheDocument()
    const productUpdates = within(panel).getByRole('switch', { name: /Product updates/ })
    expect(productUpdates).not.toBeChecked()

    await user.click(productUpdates)

    expect(productUpdates).toBeChecked()
    expect(await screen.findByText('Preference updated')).toBeInTheDocument()
  })
})
