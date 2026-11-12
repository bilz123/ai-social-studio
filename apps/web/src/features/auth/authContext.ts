import { createContext } from 'react'
import type { User } from './api/auth'

export interface AuthState {
  /** The signed-in user, or null when signed out. */
  user: User | null
  /** True until the first "who am I" answer arrives. */
  loading: boolean
  signIn: (user: User) => void
  signOut: () => Promise<void>
  /** Re-reads the user from the server (after verifying email, editing the profile…). */
  refresh: () => Promise<void>
}

export const AuthContext = createContext<AuthState | null>(null)

export const CURRENT_USER_KEY = ['auth', 'me'] as const
