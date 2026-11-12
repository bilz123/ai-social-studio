type Tone = 'ok' | 'fail' | 'pending'

const STYLES: Record<Tone, string> = {
  ok: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200',
  fail: 'bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-200',
  pending: 'bg-slate-200 text-slate-700 dark:bg-slate-800 dark:text-slate-300',
}

const LABELS: Record<Tone, string> = { ok: 'Operational', fail: 'Down', pending: 'Checking…' }

export function StatusBadge({ tone }: { tone: Tone }) {
  return (
    <span className={`inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium ${STYLES[tone]}`}>
      {LABELS[tone]}
    </span>
  )
}

export type { Tone }
