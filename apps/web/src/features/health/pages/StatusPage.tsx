import { ApiError } from '@/shared/lib/apiClient'
import { env } from '@/shared/lib/env'
import { StatusBadge, type Tone } from '../components/StatusBadge'
import { useApiHealth } from '../hooks/useApiHealth'

function describeError(error: unknown): string {
  if (error instanceof ApiError) {
    return error.requestId ? `${error.message} (request ${error.requestId})` : error.message
  }
  return 'Unexpected error.'
}

export function StatusPage() {
  const { live, ready } = useApiHealth()

  const liveTone: Tone = live.isPending ? 'pending' : live.isError ? 'fail' : 'ok'
  const readyTone: Tone = ready.isPending
    ? 'pending'
    : ready.isError || ready.data?.status === 'degraded'
      ? 'fail'
      : 'ok'

  return (
    <main className="mx-auto max-w-2xl px-4 py-10">
      <h1 className="text-2xl font-semibold">{env.appName}</h1>
      <p className="mt-1 text-sm text-slate-600 dark:text-slate-400">
        Foundation check: the browser can reach the backend API.
      </p>

      <section aria-live="polite" className="mt-8 space-y-4">
        <div className="rounded-lg border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
          <div className="flex items-center justify-between">
            <h2 className="font-medium">API (liveness)</h2>
            <StatusBadge tone={liveTone} />
          </div>
          {live.isError && (
            <p role="alert" className="mt-2 text-sm text-red-700 dark:text-red-300">
              {describeError(live.error)}
            </p>
          )}
        </div>

        <div className="rounded-lg border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
          <div className="flex items-center justify-between">
            <h2 className="font-medium">API dependencies (readiness)</h2>
            <StatusBadge tone={readyTone} />
          </div>
          {ready.isError && (
            <p role="alert" className="mt-2 text-sm text-red-700 dark:text-red-300">
              {describeError(ready.error)}
            </p>
          )}
          {ready.data && (
            <ul className="mt-3 space-y-1 text-sm">
              {Object.entries(ready.data.checks).map(([name, result]) => (
                <li key={name} className="flex justify-between">
                  <span className="capitalize">{name}</span>
                  <span>{result.status === 'ok' ? 'ok' : 'unavailable'}</span>
                </li>
              ))}
            </ul>
          )}
        </div>
      </section>
    </main>
  )
}
