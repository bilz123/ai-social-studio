import { Moon, Sun } from 'lucide-react'
import { useTheme } from '@/shared/theme/useTheme'

/** Quick light/dark switch. The three-way choice (including "system") lives in Settings. */
export function ThemeToggle() {
  const { resolved, setChoice } = useTheme()
  const next = resolved === 'dark' ? 'light' : 'dark'
  const Icon = resolved === 'dark' ? Sun : Moon

  return (
    <button
      type="button"
      onClick={() => setChoice(next)}
      aria-label={`Switch to ${next} mode`}
      className="grid size-10 place-items-center rounded-lg text-muted transition-colors hover:bg-sunken hover:text-ink"
    >
      <Icon aria-hidden="true" className="size-[18px]" />
    </button>
  )
}
