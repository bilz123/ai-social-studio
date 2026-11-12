import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { mockApi, renderApp, signedInHandlers } from '@/test/renderApp'
import { NAV_ITEMS } from './navItems'

afterEach(() => vi.unstubAllGlobals())

const mainNav = () => screen.getAllByRole('navigation', { name: 'Main' })[0] as HTMLElement

describe('sidebar', () => {
  it('lists every section in order and marks the current page', async () => {
    mockApi(signedInHandlers())
    renderApp('/videos')

    await screen.findByRole('heading', { name: 'My Videos' })
    const links = within(mainNav()).getAllByRole('link')
    expect(links.map((link) => link.textContent)).toEqual(NAV_ITEMS.map((item) => item.label))
    expect(NAV_ITEMS.map((item) => item.label)).toEqual([
      'Dashboard',
      'Create Video',
      'Connected Accounts',
      'My Videos',
      'Publishing History',
      'Scheduled Posts',
      'Analytics',
      'Settings',
    ])
    expect(within(mainNav()).getByRole('link', { name: 'My Videos' })).toHaveAttribute(
      'aria-current',
      'page',
    )
    expect(within(mainNav()).getByRole('link', { name: 'Dashboard' })).not.toHaveAttribute(
      'aria-current',
    )
  })

  it.each([
    ['/create', 'Create Video'],
    ['/history', 'Publishing History'],
    ['/scheduled', 'Scheduled Posts'],
    ['/analytics', 'Analytics'],
  ])('shows a labelled placeholder for %s', async (path, title) => {
    mockApi(signedInHandlers())
    renderApp(path)

    expect(await screen.findByRole('heading', { level: 1, name: title })).toBeInTheDocument()
    expect(screen.getByText('Coming soon')).toBeInTheDocument()
  })
})

describe('user menu', () => {
  it('opens with the account details, supports arrow keys and closes on Escape', async () => {
    mockApi(signedInHandlers())
    const user = userEvent.setup()
    renderApp('/dashboard')

    const trigger = await screen.findByRole('button', { name: /Account menu/ })
    expect(trigger).toHaveAttribute('aria-expanded', 'false')
    await user.click(trigger)

    const menu = screen.getByRole('menu', { name: 'Account' })
    expect(within(menu).getByText('ada@example.com')).toBeInTheDocument()
    const items = within(menu).getAllByRole('menuitem')
    expect(items.map((item) => item.textContent)).toEqual(['Settings', 'Sign out'])

    items[0]?.focus()
    await user.keyboard('{ArrowDown}')
    expect(items[1]).toHaveFocus()
    await user.keyboard('{ArrowDown}')
    expect(items[0]).toHaveFocus()

    await user.keyboard('{Escape}')
    expect(screen.queryByRole('menu')).not.toBeInTheDocument()
    expect(trigger).toHaveFocus()
  })

  it('closes when clicking elsewhere', async () => {
    mockApi(signedInHandlers())
    const user = userEvent.setup()
    renderApp('/dashboard')

    await user.click(await screen.findByRole('button', { name: /Account menu/ }))
    await user.click(document.body)
    expect(screen.queryByRole('menu')).not.toBeInTheDocument()
  })
})

describe('notifications', () => {
  it('shows the unread count, lists items and marks all as read with a toast', async () => {
    mockApi(signedInHandlers())
    const user = userEvent.setup()
    renderApp('/dashboard')

    const bell = await screen.findByRole('button', { name: /Notifications/ })
    await user.click(bell)

    const panel = screen.getByRole('dialog', { name: 'Notifications' })
    expect(await within(panel).findByText('Video ready for review')).toBeInTheDocument()
    expect(within(panel).getByText('Sample data')).toBeInTheDocument()
    await waitFor(() => expect(bell).toHaveAccessibleName('Notifications, 3 unread'))

    await user.click(within(panel).getByRole('button', { name: 'Mark all read' }))

    expect(await screen.findByText('All notifications marked as read')).toBeInTheDocument()
    expect(bell).toHaveAccessibleName('Notifications')
    expect(within(panel).getByRole('button', { name: 'Mark all read' })).toBeDisabled()
  })
})

describe('mobile navigation drawer', () => {
  it('opens as a modal dialog, navigates and closes', async () => {
    mockApi(signedInHandlers())
    const user = userEvent.setup()
    renderApp('/dashboard')

    await user.click(await screen.findByRole('button', { name: 'Open navigation' }))
    const drawer = screen.getByRole('dialog', { name: 'Navigation' })
    expect(drawer).toHaveAttribute('aria-modal', 'true')

    await user.click(within(drawer).getByRole('link', { name: 'Analytics' }))
    expect(screen.queryByRole('dialog', { name: 'Navigation' })).not.toBeInTheDocument()
    expect(await screen.findByRole('heading', { level: 1, name: 'Analytics' })).toBeInTheDocument()
  })

  it('closes on Escape and returns focus to the menu button', async () => {
    mockApi(signedInHandlers())
    const user = userEvent.setup()
    renderApp('/dashboard')

    const opener = await screen.findByRole('button', { name: 'Open navigation' })
    await user.click(opener)
    await user.keyboard('{Escape}')

    expect(screen.queryByRole('dialog', { name: 'Navigation' })).not.toBeInTheDocument()
    expect(opener).toHaveFocus()
  })
})
