// Single place that reads browser-visible configuration (VITE_* only; these are public values).
const raw = import.meta.env

export const env = {
  /** Backend API base URL, without trailing slash. Relative by default (same-origin). */
  apiBaseUrl: (raw.VITE_API_BASE_URL ?? '/api').replace(/\/+$/, ''),
  appName: raw.VITE_APP_NAME ?? 'AI Social Studio',
} as const
