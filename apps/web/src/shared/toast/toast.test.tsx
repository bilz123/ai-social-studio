import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it } from 'vitest'
import { ToastProvider } from './ToastProvider'
import { useToast } from './useToast'

function Trigger() {
  const toast = useToast()
  return (
    <>
      <button
        onClick={() => toast.show({ tone: 'success', title: 'Saved', description: 'All good.' })}
      >
        ok
      </button>
      <button onClick={() => toast.show({ tone: 'error', title: 'Failed' })}>bad</button>
    </>
  )
}

const setup = () =>
  render(
    <ToastProvider>
      <Trigger />
    </ToastProvider>,
  )

describe('toasts', () => {
  it('announces a success politely and can be dismissed', async () => {
    const user = userEvent.setup()
    setup()

    await user.click(screen.getByText('ok'))
    const live = screen.getByRole('status')
    expect(live).toHaveTextContent('Saved')
    expect(live).toHaveTextContent('All good.')

    await user.click(screen.getByRole('button', { name: 'Dismiss notification' }))
    expect(screen.queryByText('Saved')).not.toBeInTheDocument()
  })

  it('announces errors assertively', async () => {
    const user = userEvent.setup()
    setup()

    await user.click(screen.getByText('bad'))
    expect(screen.getByRole('alert')).toHaveTextContent('Failed')
  })

  it('keeps at most four messages on screen', async () => {
    const user = userEvent.setup()
    setup()

    for (let i = 0; i < 6; i += 1) await user.click(screen.getByText('ok'))
    expect(screen.getAllByRole('button', { name: 'Dismiss notification' })).toHaveLength(4)
  })
})
