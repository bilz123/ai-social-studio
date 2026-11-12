import { useQuery } from '@tanstack/react-query'
import { Link, useSearchParams } from 'react-router-dom'
import { Alert } from '@/shared/ui/Alert'
import { verifyEmail } from '../api/auth'
import { useAuth } from '../useAuth'

export function VerifyEmailPage() {
  const [params] = useSearchParams()
  const { refresh } = useAuth()
  const id = params.get('id') ?? ''
  const hash = params.get('hash') ?? ''
  const expires = params.get('expires') ?? ''
  const signature = params.get('signature') ?? ''
  const complete = Boolean(id && hash && expires && signature)

  const query = useQuery({
    queryKey: ['auth', 'verify', id, signature],
    enabled: complete,
    retry: false,
    staleTime: Infinity,
    queryFn: async () => {
      const result = await verifyEmail({ id, hash, expires, signature })
      await refresh()
      return result
    },
  })

  const failed = !complete || query.isError

  return (
    <>
      <h1 className="font-display text-4xl font-semibold tracking-tight">Email verification</h1>
      <div className="mt-8">
        {query.isPending && complete && <Alert tone="info">Checking your link…</Alert>}
        {query.isSuccess && <Alert tone="success">Your email address is verified.</Alert>}
        {failed && (
          <Alert tone="error">
            This verification link is invalid or has expired. Sign in and request a new one from
            your profile.
          </Alert>
        )}
      </div>
      <p className="mt-8 text-sm">
        <Link to="/dashboard" className="font-semibold underline underline-offset-4">
          Continue to the studio
        </Link>
      </p>
    </>
  )
}
