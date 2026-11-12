import { useQuery, useQueryClient } from '@tanstack/react-query'
import { useCallback, useMemo } from 'react'
import type { ReactNode } from 'react'
import { ApiError } from '@/shared/lib/apiClient'
import { fetchCurrentUser, logout } from './api/auth'
import type { User } from './api/auth'
import { AuthContext, CURRENT_USER_KEY } from './authContext'
import type { AuthState } from './authContext'

/**
 * Holds the signed-in user. The server session cookie is the source of truth: the browser never
 * stores a token, it only asks "who am I?" (a 401 simply means signed out).
 */
export function AuthProvider({ children }: { children: ReactNode }) {
  const queryClient = useQueryClient()

  const { data, isPending } = useQuery<User | null>({
    queryKey: CURRENT_USER_KEY,
    queryFn: async () => {
      try {
        return await fetchCurrentUser()
      } catch (error) {
        if (error instanceof ApiError && error.status === 401) return null
        throw error
      }
    },
    staleTime: 60_000,
    retry: false,
  })

  const signIn = useCallback(
    (user: User) => queryClient.setQueryData(CURRENT_USER_KEY, user),
    [queryClient],
  )

  const signOut = useCallback(async () => {
    try {
      await logout()
    } finally {
      // Whatever the server said, nothing from the previous session may stay in memory.
      queryClient.removeQueries({ predicate: (query) => query.queryKey[0] !== CURRENT_USER_KEY[0] })
      queryClient.setQueryData(CURRENT_USER_KEY, null)
    }
  }, [queryClient])

  const refresh = useCallback(
    () => queryClient.invalidateQueries({ queryKey: CURRENT_USER_KEY }),
    [queryClient],
  )

  const value = useMemo<AuthState>(
    () => ({ user: data ?? null, loading: isPending, signIn, signOut, refresh }),
    [data, isPending, signIn, signOut, refresh],
  )

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>
}
