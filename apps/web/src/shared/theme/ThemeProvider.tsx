import { useCallback, useEffect, useMemo, useState } from 'react'
import type { ReactNode } from 'react'
import { THEME_STORAGE_KEY, ThemeContext, applyTheme } from './themeContext'
import type { ThemeChoice, ThemeState } from './themeContext'

function readStoredChoice(): ThemeChoice {
  try {
    const value = window.localStorage.getItem(THEME_STORAGE_KEY)
    return value === 'light' || value === 'dark' ? value : 'system'
  } catch {
    return 'system'
  }
}

function systemPrefersDark(): boolean {
  return typeof window.matchMedia === 'function'
    ? window.matchMedia('(prefers-color-scheme: dark)').matches
    : false
}

export function ThemeProvider({ children }: { children: ReactNode }) {
  const [choice, setChoiceState] = useState<ThemeChoice>(readStoredChoice)
  const [systemDark, setSystemDark] = useState(systemPrefersDark)

  useEffect(() => {
    applyTheme(choice)
  }, [choice])

  useEffect(() => {
    if (typeof window.matchMedia !== 'function') return
    const query = window.matchMedia('(prefers-color-scheme: dark)')
    const onChange = (event: MediaQueryListEvent) => setSystemDark(event.matches)
    query.addEventListener('change', onChange)
    return () => query.removeEventListener('change', onChange)
  }, [])

  const setChoice = useCallback((next: ThemeChoice) => {
    setChoiceState(next)
    try {
      if (next === 'system') window.localStorage.removeItem(THEME_STORAGE_KEY)
      else window.localStorage.setItem(THEME_STORAGE_KEY, next)
    } catch {
      // Storage can be blocked (private windows); the choice still applies for this visit.
    }
  }, [])

  const value = useMemo<ThemeState>(
    () => ({
      choice,
      resolved: choice === 'system' ? (systemDark ? 'dark' : 'light') : choice,
      setChoice,
    }),
    [choice, systemDark, setChoice],
  )

  return <ThemeContext.Provider value={value}>{children}</ThemeContext.Provider>
}
