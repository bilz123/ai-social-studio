import { useContext } from 'react'
import { ThemeContext } from './themeContext'
import type { ThemeState } from './themeContext'

export function useTheme(): ThemeState {
  const value = useContext(ThemeContext)
  if (!value) throw new Error('useTheme must be used inside <ThemeProvider>')
  return value
}
