import { useQuery } from '@tanstack/react-query'
import { fetchLiveHealth, fetchReadyHealth } from '../api/health'

const REFRESH_MS = 15_000

export function useApiHealth() {
  const live = useQuery({
    queryKey: ['health', 'live'],
    queryFn: ({ signal }) => fetchLiveHealth(signal),
    refetchInterval: REFRESH_MS,
  })

  const ready = useQuery({
    queryKey: ['health', 'ready'],
    queryFn: ({ signal }) => fetchReadyHealth(signal),
    refetchInterval: REFRESH_MS,
  })

  return { live, ready }
}
