import { screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { mockApi, renderApp, signedInHandlers } from '@/test/renderApp'
import { THEME_STORAGE_KEY } from './themeContext'

afterEach(() => vi.unstubAllGlobals())

describe('theme', () => {
  it('switches to dark from the header and remembers the choice', async () => {
    mockApi(signedInHandlers())
    const user = userEvent.setup()
    renderApp('/dashboard')

    await user.click(await screen.findByRole('button', { name: 'Switch to dark mode' }))

    expect(document.documentElement).toHaveAttribute('data-theme', 'dark')
    expect(window.localStorage.getItem(THEME_STORAGE_KEY)).toBe('dark')
    expect(screen.getByRole('button', { name: 'Switch to light mode' })).toBeInTheDocument()
  })

  it('restores the saved choice on load', async () => {
    window.localStorage.setItem(THEME_STORAGE_KEY, 'dark')
    mockApi(signedInHandlers())
    renderApp('/dashboard')

    await screen.findByRole('heading', { level: 1 })
    expect(document.documentElement).toHaveAttribute('data-theme', 'dark')
  })

  it('offers light, dark and system in Settings and "system" follows the device', async () => {
    window.localStorage.setItem(THEME_STORAGE_KEY, 'dark')
    mockApi(signedInHandlers())
    const user = userEvent.setup()
    renderApp('/settings?tab=appearance')

    const dark = await screen.findByRole('radio', { name: /Dark/ })
    expect(dark).toBeChecked()
    expect(screen.getByRole('radio', { name: /Light/ })).not.toBeChecked()

    await user.click(screen.getByRole('radio', { name: /System/ }))
    expect(document.documentElement).not.toHaveAttribute('data-theme')
    expect(window.localStorage.getItem(THEME_STORAGE_KEY)).toBeNull()

    await user.click(screen.getByRole('radio', { name: /Light/ }))
    expect(document.documentElement).toHaveAttribute('data-theme', 'light')
  })
})
