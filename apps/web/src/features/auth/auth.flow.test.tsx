import { screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { emptyActivity, jsonResponse, mockApi, renderApp, sampleUser } from '@/test/renderApp'

const signedOut = () => jsonResponse({ code: 'unauthenticated', detail: 'Unauthenticated.' }, 401)

afterEach(() => {
  vi.unstubAllGlobals()
  document.cookie = 'XSRF-TOKEN=; expires=Thu, 01 Jan 1970 00:00:00 GMT'
})

describe('protected routes', () => {
  it('sends a signed-out visitor from the dashboard to the sign-in page', async () => {
    mockApi({ 'GET /v1/auth/me': signedOut })

    const { router } = renderApp('/dashboard')

    expect(await screen.findByRole('heading', { name: 'Welcome back' })).toBeInTheDocument()
    expect(router.state.location.pathname).toBe('/login')
  })

  it('shows the dashboard to a signed-in user', async () => {
    mockApi({
      'GET /v1/auth/me': () => jsonResponse({ data: sampleUser }),
      'GET /v1/account/activity': emptyActivity,
    })

    renderApp('/dashboard')

    expect(await screen.findByRole('heading', { name: 'Hello, Ada' })).toBeInTheDocument()
  })

  it('keeps a signed-in user out of the sign-in page', async () => {
    mockApi({
      'GET /v1/auth/me': () => jsonResponse({ data: sampleUser }),
      'GET /v1/account/activity': emptyActivity,
    })

    const { router } = renderApp('/login')

    await screen.findByRole('heading', { name: 'Hello, Ada' })
    expect(router.state.location.pathname).toBe('/dashboard')
  })

  it('offers to verify the email of an unverified user', async () => {
    mockApi({
      'GET /v1/auth/me': () =>
        jsonResponse({ data: { ...sampleUser, email_verified: false, email_verified_at: null } }),
      'GET /v1/account/activity': emptyActivity,
    })

    renderApp('/dashboard')

    expect(
      await screen.findByRole('button', { name: 'Send verification email' }),
    ).toBeInTheDocument()
  })
})

describe('sign in', () => {
  it('signs in and lands on the dashboard', async () => {
    document.cookie = 'XSRF-TOKEN=token'
    mockApi({
      'GET /v1/auth/me': signedOut,
      'GET /v1/account/activity': emptyActivity,
      'POST /v1/auth/login': (init) => {
        expect(JSON.parse(String(init?.body))).toEqual({
          email: 'ada@example.com',
          password: 'correct horse battery',
        })
        return jsonResponse({ data: sampleUser })
      },
    })
    const user = userEvent.setup()

    const { router } = renderApp('/login')
    await user.type(await screen.findByLabelText('Email'), 'ada@example.com')
    await user.type(screen.getByLabelText('Password'), 'correct horse battery')
    await user.click(screen.getByRole('button', { name: 'Sign in' }))

    await screen.findByRole('heading', { name: 'Hello, Ada' })
    expect(router.state.location.pathname).toBe('/dashboard')
  })

  it('shows the server validation message under the field', async () => {
    document.cookie = 'XSRF-TOKEN=token'
    mockApi({
      'GET /v1/auth/me': signedOut,
      'POST /v1/auth/login': () =>
        jsonResponse(
          {
            code: 'validation_failed',
            errors: { email: ['These credentials do not match our records.'] },
          },
          422,
        ),
    })
    const user = userEvent.setup()

    renderApp('/login')
    await user.type(await screen.findByLabelText('Email'), 'ada@example.com')
    await user.type(screen.getByLabelText('Password'), 'wrong')
    await user.click(screen.getByRole('button', { name: 'Sign in' }))

    expect(
      await screen.findByText('These credentials do not match our records.'),
    ).toBeInTheDocument()
  })
})

describe('sign out', () => {
  it('ends the session and returns to the sign-in page', async () => {
    document.cookie = 'XSRF-TOKEN=token'
    const calls = mockApi({
      'GET /v1/auth/me': () => jsonResponse({ data: sampleUser }),
      'GET /v1/account/activity': emptyActivity,
      'POST /v1/auth/logout': () => new Response(null, { status: 204 }),
    })
    const user = userEvent.setup()

    const { router } = renderApp('/dashboard')
    await user.click(await screen.findByRole('button', { name: /Account menu/ }))
    await user.click(screen.getByRole('menuitem', { name: 'Sign out' }))

    await waitFor(() => expect(router.state.location.pathname).toBe('/login'))
    expect(calls).toContain('POST /v1/auth/logout')
  })
})

describe('register', () => {
  it('blocks submission while the passwords differ', async () => {
    const calls = mockApi({ 'GET /v1/auth/me': signedOut })
    const user = userEvent.setup()

    renderApp('/register')
    await user.type(await screen.findByLabelText('Full name'), 'Ada Lovelace')
    await user.type(screen.getByLabelText('Email'), 'ada@example.com')
    await user.type(screen.getByLabelText('Password'), 'Str0ng!Passphrase#1')
    await user.type(screen.getByLabelText('Confirm password'), 'different')
    await user.click(screen.getByRole('button', { name: 'Create account' }))

    expect(screen.getByText('The passwords do not match.')).toBeInTheDocument()
    expect(calls).not.toContain('POST /v1/auth/register')
  })
})

describe('password reset', () => {
  it('confirms without revealing whether the account exists', async () => {
    document.cookie = 'XSRF-TOKEN=token'
    mockApi({
      'GET /v1/auth/me': signedOut,
      'POST /v1/auth/forgot-password': () => jsonResponse({ message: 'ok' }, 200),
    })
    const user = userEvent.setup()

    renderApp('/forgot-password')
    await user.type(await screen.findByLabelText('Email'), 'nobody@example.com')
    await user.click(screen.getByRole('button', { name: 'Send reset link' }))

    expect(
      await screen.findByText(/If an account exists for nobody@example.com/),
    ).toBeInTheDocument()
  })

  it('rejects a reset link that has no token', async () => {
    mockApi({ 'GET /v1/auth/me': signedOut })

    renderApp('/reset-password')

    expect(await screen.findByText(/This reset link is incomplete/)).toBeInTheDocument()
  })

  it('sets the new password from a valid link', async () => {
    document.cookie = 'XSRF-TOKEN=token'
    mockApi({
      'GET /v1/auth/me': signedOut,
      'POST /v1/auth/reset-password': (init) => {
        expect(JSON.parse(String(init?.body))).toMatchObject({
          token: 'abc',
          email: 'ada@example.com',
        })
        return jsonResponse({ message: 'done' })
      },
    })
    const user = userEvent.setup()

    renderApp('/reset-password?token=abc&email=ada%40example.com')
    await user.type(await screen.findByLabelText('New password'), 'Str0ng!Passphrase#2')
    await user.type(screen.getByLabelText('Confirm new password'), 'Str0ng!Passphrase#2')
    await user.click(screen.getByRole('button', { name: 'Update password' }))

    expect(await screen.findByRole('heading', { name: 'Password updated' })).toBeInTheDocument()
  })
})
