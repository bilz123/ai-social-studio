import { Link } from 'react-router-dom'

export function NotFoundPage() {
  return (
    <main className="mx-auto max-w-2xl px-4 py-10">
      <h1 className="text-2xl font-semibold">Page not found</h1>
      <p className="mt-2 text-slate-600 dark:text-slate-400">
        The page you are looking for does not exist.
      </p>
      <Link to="/" className="mt-4 inline-block text-blue-700 underline dark:text-blue-300">
        Back to start
      </Link>
    </main>
  )
}
