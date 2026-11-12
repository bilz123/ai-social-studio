import { useAuth } from '@/features/auth/useAuth'

export function DashboardPage() {
  const { user } = useAuth()
  const firstName = user?.name.split(' ')[0] ?? ''

  return (
    <>
      <h1 className="font-display text-4xl font-semibold tracking-tight">Hello, {firstName}</h1>
      <p className="mt-3 max-w-prose text-muted">
        Your account is ready. Projects, video generation and publishing arrive in the next phases;
        nothing here needs setting up yet.
      </p>
    </>
  )
}
