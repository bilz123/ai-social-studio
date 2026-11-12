import { useQuery } from '@tanstack/react-query'
import {
  sampleAccounts,
  sampleNotifications,
  samplePublishing,
  sampleStats,
  sampleVideos,
} from './sampleData'

/** Simulated network delay so loading skeletons are visible and exercised. */
export const SAMPLE_LATENCY_MS = 350

function useSample<T>(key: string, factory: () => T) {
  return useQuery({
    queryKey: ['sample', key],
    queryFn: async (): Promise<T> => {
      await new Promise((resolve) => setTimeout(resolve, SAMPLE_LATENCY_MS))
      return factory()
    },
    staleTime: Infinity,
  })
}

// PLACEHOLDER: swap each of these for an apiFetch call when its endpoint exists.
export const useSampleStats = () => useSample('stats', sampleStats)
export const useSampleVideos = () => useSample('videos', sampleVideos)
export const useSampleAccounts = () => useSample('accounts', sampleAccounts)
export const useSamplePublishing = () => useSample('publishing', samplePublishing)
export const useSampleNotifications = () => useSample('notifications', sampleNotifications)
