import { QueryClientProvider } from '@tanstack/react-query'
import { useState } from 'react'
import { RouterProvider } from 'react-router-dom'
import { AuthProvider } from '@/features/auth/AuthProvider'
import { ThemeProvider } from '@/shared/theme/ThemeProvider'
import { ToastProvider } from '@/shared/toast/ToastProvider'
import { createQueryClient } from './queryClient'
import { createRouter } from './router'

export function App() {
  const [queryClient] = useState(createQueryClient)
  const [router] = useState(createRouter)

  return (
    <QueryClientProvider client={queryClient}>
      <ThemeProvider>
        <ToastProvider>
          <AuthProvider>
            <RouterProvider router={router} />
          </AuthProvider>
        </ToastProvider>
      </ThemeProvider>
    </QueryClientProvider>
  )
}
