import { useQueryClient } from '@tanstack/react-query'
import { useCallback, useEffect, useRef, useState } from 'react'
import { ApiError } from '@/shared/lib/apiClient'
import { uploadImage } from './api/projects'
import type { ProjectEnvelope, ProjectImage, UploadLimits } from './api/projects'
import { checkFile } from './imageRules'
import { patchImages, projectKeys } from './useProjects'

export interface UploadItem {
  id: string
  file: File
  previewUrl: string
  status: 'queued' | 'uploading' | 'failed'
  /** 0 to 1 */
  progress: number
  error: string | null
}

export function describeUploadError(error: unknown): string {
  if (error instanceof ApiError) {
    const first = error.errors.image?.[0]
    if (first) return first
    if (error.status === 413) return 'This file is larger than the server accepts.'
    if (error.status === 429) return 'Too many uploads at once. Wait a minute, then retry.'
    if (error.status === 401) return 'Your session has expired. Sign in again, then retry.'
    if (error.code === 'network_error' || error.code === 'timeout') {
      return 'The upload was interrupted. Check your connection, then retry.'
    }
    return error.message
  }
  return 'The upload failed. Try again.'
}

const previewFor = (file: File): string =>
  typeof URL.createObjectURL === 'function' ? URL.createObjectURL(file) : ''

const revoke = (url: string) => {
  if (url && typeof URL.revokeObjectURL === 'function') URL.revokeObjectURL(url)
}

/**
 * The upload queue for one project. Files are checked in the browser, then sent one at a time (so
 * they land in the order they were chosen and a failure never blocks the others). Each finished
 * upload is written into the cached project, so the grid swaps the local preview for the stored
 * image without a refetch.
 */
export function useImageUploads({
  projectId,
  limits,
  savedCount,
}: {
  projectId: string
  limits: UploadLimits
  savedCount: number
}) {
  const queryClient = useQueryClient()
  const [items, setItems] = useState<UploadItem[]>([])
  const [notices, setNotices] = useState<string[]>([])

  const latest = useRef({ items, savedCount, limits })
  useEffect(() => {
    latest.current = { items, savedCount, limits }
  })
  const controllers = useRef(new Map<string, AbortController>())
  const started = useRef(new Set<string>())
  const counter = useRef(0)

  const update = useCallback((id: string, patch: Partial<UploadItem>) => {
    setItems((current) => current.map((item) => (item.id === id ? { ...item, ...patch } : item)))
  }, [])

  const drop = useCallback((id: string) => {
    controllers.current.get(id)?.abort()
    controllers.current.delete(id)
    started.current.delete(id)
    setItems((current) => {
      const gone = current.find((item) => item.id === id)
      if (gone) revoke(gone.previewUrl)
      return current.filter((item) => item.id !== id)
    })
  }, [])

  const addFiles = useCallback(async (files: File[]) => {
    const problems: string[] = []
    const accepted: File[] = []

    for (const file of files) {
      const problem = await checkFile(file, latest.current.limits)
      if (problem) problems.push(problem)
      else accepted.push(file)
    }

    const room = Math.max(
      0,
      latest.current.limits.max_images - latest.current.savedCount - latest.current.items.length,
    )
    const taken = accepted.slice(0, room)
    if (accepted.length > taken.length) {
      problems.push(
        room === 0
          ? `This project already has ${latest.current.limits.max_images} images, the most it can hold. Remove one to add another.`
          : `Only ${room} more ${room === 1 ? 'image fits' : 'images fit'} in this project (the limit is ${latest.current.limits.max_images}).`,
      )
    }

    setNotices(problems)
    if (taken.length > 0) {
      setItems((current) => [
        ...current,
        ...taken.map((file): UploadItem => ({
          id: `upload-${(counter.current += 1)}`,
          file,
          previewUrl: previewFor(file),
          status: 'queued',
          progress: 0,
          error: null,
        })),
      ])
    }
  }, [])

  // One upload at a time, in the order the files were chosen.
  useEffect(() => {
    if (items.some((item) => item.status === 'uploading')) return
    const next = items.find((item) => item.status === 'queued' && !started.current.has(item.id))
    if (!next) return

    started.current.add(next.id)
    const controller = new AbortController()
    controllers.current.set(next.id, controller)
    update(next.id, { status: 'uploading', progress: 0, error: null })

    uploadImage(projectId, next.file, {
      signal: controller.signal,
      onProgress: (fraction) => update(next.id, { progress: fraction }),
    })
      .then((image: ProjectImage) => {
        queryClient.setQueryData<ProjectEnvelope>(projectKeys.detail(projectId), (old) =>
          patchImages(old, (images) => [...images, image]),
        )
        void queryClient.invalidateQueries({ queryKey: projectKeys.lists })
        drop(next.id)
      })
      .catch((error: unknown) => {
        if (controller.signal.aborted) return
        started.current.delete(next.id)
        update(next.id, { status: 'failed', error: describeUploadError(error) })
      })
      .finally(() => controllers.current.delete(next.id))
  }, [items, projectId, queryClient, update, drop])

  // Leaving the page cancels what is still in flight and frees the preview memory.
  useEffect(() => {
    const active = controllers.current
    return () => {
      active.forEach((controller) => controller.abort())
      latest.current.items.forEach((item) => revoke(item.previewUrl))
    }
  }, [])

  const retry = useCallback(
    (id: string) => {
      started.current.delete(id)
      update(id, { status: 'queued', progress: 0, error: null })
    },
    [update],
  )

  return {
    items,
    notices,
    busy: items.some((item) => item.status !== 'failed'),
    addFiles,
    retry,
    cancel: drop,
    dismissNotices: () => setNotices([]),
  }
}
