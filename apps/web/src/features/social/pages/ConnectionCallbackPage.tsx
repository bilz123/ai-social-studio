import { CircleAlert, CircleCheck } from 'lucide-react'
import { useEffect, useRef, useState } from 'react'
import type { ReactNode } from 'react'
import { Link, Navigate, useParams, useSearchParams } from 'react-router-dom'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { PageHeader } from '@/shared/layout/PageHeader'
import { ApiError } from '@/shared/lib/apiClient'
import { useToast } from '@/shared/toast/useToast'
import { buttonClasses } from '@/shared/ui/buttonStyles'
import { Card } from '@/shared/ui/Card'
import { completeConnect, isPlatformId } from '../api/social'
import type { PlatformId } from '../api/social'
import { PLATFORM_COPY } from '../describe'
import { socialKeys } from '../useSocial'

const ERROR_TITLES: Record<string, string> = {
  invalid_oauth_state: 'This connection attempt expired',
  authorization_denied: 'Access was not granted',
  authorization_failed: 'The platform rejected the sign-in',
  missing_permissions: 'Some permissions were not approved',
  account_not_eligible: 'This account cannot be connected',
  integration_not_configured: 'Not set up on this server',
}

/**
 * Where the provider sends the person back to. It forwards the `code` and `state` from the URL to
 * the API exactly once, then shows what happened. The API checks the state; this page never trusts
 * the URL on its own.
 */
export function ConnectionCallbackPage() {
  const { platform } = useParams()
  if (!isPlatformId(platform)) return <Navigate to="/accounts" replace />
  return <Callback platform={platform} />
}

function Callback({ platform }: { platform: PlatformId }) {
  const name = PLATFORM_COPY[platform].name
  const toast = useToast()
  const queryClient = useQueryClient()
  const [searchParams, setSearchParams] = useSearchParams()

  // Read the URL once, then clear it so the one-time code does not linger in history.
  const [params] = useState(() => ({
    state: searchParams.get('state') ?? '',
    code: searchParams.get('code') ?? undefined,
    error: searchParams.get('error') ?? undefined,
  }))
  const cleared = useRef(false)
  useEffect(() => {
    if (cleared.current) return
    cleared.current = true
    setSearchParams({}, { replace: true })
  }, [setSearchParams])

  const result = useQuery({
    queryKey: ['social', 'callback', platform, params.state],
    queryFn: async () => {
      const accounts = await completeConnect(platform, {
        state: params.state,
        code: params.code,
        error: params.error,
      })
      await queryClient.invalidateQueries({ queryKey: socialKeys.accounts })
      await queryClient.invalidateQueries({ queryKey: socialKeys.platforms })
      return accounts
    },
    enabled: params.state !== '',
    // The state is single use: never retry or refetch this request.
    retry: false,
    staleTime: Infinity,
    gcTime: Infinity,
  })

  const announced = useRef(false)
  useEffect(() => {
    if (result.isSuccess && !announced.current) {
      announced.current = true
      const count = result.data.length
      toast.show({
        tone: 'success',
        title: `${name} connected`,
        description: `${count} ${count === 1 ? 'account' : 'accounts'} added.`,
      })
    }
  }, [result.isSuccess, result.data, name, toast])

  const back = (
    <Link to="/accounts" className={buttonClasses('primary')}>
      Back to connected accounts
    </Link>
  )

  let body
  if (params.state === '') {
    body = (
      <Outcome
        tone="error"
        title="No connection in progress"
        description={`Start from Connected Accounts and choose ${name}.`}
        action={back}
      />
    )
  } else if (result.isPending) {
    body = (
      <div role="status" className="flex flex-col items-center px-6 py-12 text-center">
        <span
          aria-hidden="true"
          className="size-8 animate-spin rounded-full border-2 border-brand border-t-transparent"
        />
        <p className="mt-4 font-display text-base font-semibold">
          Finishing your {name} connection
        </p>
        <p className="mt-1 text-sm text-muted">This takes a few seconds.</p>
      </div>
    )
  } else if (result.isError) {
    const error = result.error
    const code = error instanceof ApiError ? error.code : ''
    body = (
      <Outcome
        tone="error"
        title={ERROR_TITLES[code] ?? `Could not connect ${name}`}
        description={error instanceof ApiError ? error.message : 'Something went wrong. Try again.'}
        action={back}
      />
    )
  } else {
    body = (
      <Outcome
        tone="success"
        title={`${name} is connected`}
        description={result.data.map((account) => account.name).join(', ')}
        action={back}
      />
    )
  }

  return (
    <>
      <PageHeader title={`Connecting ${name}`} />
      <Card className="mx-auto max-w-xl">{body}</Card>
    </>
  )
}

function Outcome({
  tone,
  title,
  description,
  action,
}: {
  tone: 'success' | 'error'
  title: string
  description: string
  action: ReactNode
}) {
  const Icon = tone === 'success' ? CircleCheck : CircleAlert
  return (
    <div
      role={tone === 'error' ? 'alert' : 'status'}
      className="flex flex-col items-center px-6 py-12 text-center"
    >
      <span
        className={
          tone === 'success'
            ? 'grid size-11 place-items-center rounded-full bg-ok-bg text-ok'
            : 'grid size-11 place-items-center rounded-full bg-danger-bg text-danger'
        }
      >
        <Icon aria-hidden="true" className="size-5" />
      </span>
      <h2 className="mt-4 font-display text-lg font-semibold">{title}</h2>
      <p className="mt-1 max-w-sm text-sm text-muted">{description}</p>
      <div className="mt-6">{action}</div>
    </div>
  )
}
