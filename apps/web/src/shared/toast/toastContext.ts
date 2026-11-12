import { createContext } from 'react'

export type ToastTone = 'success' | 'error' | 'info'

export interface ToastOptions {
  title: string
  description?: string
  tone?: ToastTone
}

export interface ToastApi {
  show: (options: ToastOptions) => void
}

export const ToastContext = createContext<ToastApi | null>(null)
