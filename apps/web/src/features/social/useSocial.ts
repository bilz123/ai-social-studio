import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { ApiError } from '@/shared/lib/apiClient'
import { redirectTo } from '@/shared/lib/navigation'
import { useToast } from '@/shared/toast/useToast'
import {
  checkAccount,
  disconnectAccount,
  fetchAccounts,
  fetchPlatforms,
  setAccountEnabled,
  startConnect,
} from './api/social'

export const socialKeys = {
  all: ['social'] as const,
  platforms: ['social', 'platforms'] as const,
  accounts: ['social', 'accounts'] as const,
}

export const usePlatforms = () =>
  useQuery({ queryKey: socialKeys.platforms, queryFn: fetchPlatforms })

export const useSocialAccounts = () =>
  useQuery({ queryKey: socialKeys.accounts, queryFn: fetchAccounts })

export const messageOf = (error: unknown): string =>
  error instanceof ApiError ? error.message : 'Something went wrong. Try again.'

/** Starts OAuth and sends the browser to the provider's consent screen. */
export function useConnectPlatform() {
  const toast = useToast()
  return useMutation({
    mutationFn: startConnect,
    onSuccess: (started) => redirectTo(started.authorization_url),
    onError: (error) =>
      toast.show({
        tone: 'error',
        title: 'Could not start the connection',
        description: messageOf(error),
      }),
  })
}

/** "Check connection": renews the token if due and re-reads the account from the provider. */
export function useCheckAccount() {
  const queryClient = useQueryClient()
  const toast = useToast()
  return useMutation({
    mutationFn: checkAccount,
    onSuccess: async (account) => {
      await queryClient.invalidateQueries({ queryKey: socialKeys.all })
      toast.show({ tone: 'success', title: `${account.name} is connected` })
    },
    onError: async (error) => {
      // The server may have changed the account's status (for example to "needs reconnect").
      await queryClient.invalidateQueries({ queryKey: socialKeys.all })
      toast.show({ tone: 'error', title: 'Connection check failed', description: messageOf(error) })
    },
  })
}

export function useToggleAccount() {
  const queryClient = useQueryClient()
  const toast = useToast()
  return useMutation({
    mutationFn: setAccountEnabled,
    onSuccess: async (account) => {
      await queryClient.invalidateQueries({ queryKey: socialKeys.all })
      toast.show({
        tone: 'success',
        title: account.is_enabled
          ? `Publishing to ${account.name} is on`
          : `Publishing to ${account.name} is paused`,
      })
    },
    onError: (error) =>
      toast.show({
        tone: 'error',
        title: 'Could not update the account',
        description: messageOf(error),
      }),
  })
}

export function useDisconnectAccount() {
  const queryClient = useQueryClient()
  const toast = useToast()
  return useMutation({
    mutationFn: ({ id }: { id: string; name: string }) => disconnectAccount(id),
    onSuccess: async (_data, variables) => {
      await queryClient.invalidateQueries({ queryKey: socialKeys.all })
      toast.show({ tone: 'success', title: `Disconnected ${variables.name}` })
    },
    onError: (error) =>
      toast.show({ tone: 'error', title: 'Could not disconnect', description: messageOf(error) }),
  })
}
