import { createContext } from 'react'

export type ThemeChoice = 'light' | 'dark' | 'system'

export interface ThemeState {
  /** What the person picked. "system" follows the device. */
  choice: ThemeChoice
  /** What is actually showing right now. */
  resolved: 'light' | 'dark'
  setChoice: (choice: ThemeChoice) => void
}

export const ThemeContext = createContext<ThemeState | null>(null)

export const THEME_STORAGE_KEY = 'studio-theme'

/** Applies the choice to <html data-theme>. "system" removes the attribute so CSS follows the OS. */
export function applyTheme(choice: ThemeChoice) {
  const root = document.documentElement
  if (choice === 'system') root.removeAttribute('data-theme')
  else root.setAttribute('data-theme', choice)
}
