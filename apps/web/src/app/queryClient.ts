import { QueryClient } from '@tanstack/react-query'
import { ApiError } from '@/shared/lib/apiClient'

export function createQueryClient(): QueryClient {
  return new QueryClient({
    defaultOptions: {
      queries: {
        staleTime: 10_000,
        refetchOnWindowFocus: false,
        // Retry only transient failures (network / 5xx); never retry 4xx.
        retry: (failureCount, error) => {
          if (error instanceof ApiError && error.status >= 400 && error.status < 500) return false
          return failureCount < 2
        },
      },
    },
  })
}
