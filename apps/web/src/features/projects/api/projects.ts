import { ApiError, apiFetch, apiUrl, mutatingHeaders, problemToError } from '@/shared/lib/apiClient'

export type ProjectStatus = 'draft' | 'ready' | 'archived'

export interface ProjectImage {
  id: string
  name: string
  mime: string
  bytes: number
  width: number | null
  height: number | null
  /** 1-based; the first image is the project's cover. */
  position: number
  status: string
  /** Temporary signed links: they stop working at `url_expires_at`. */
  thumbnail_url: string | null
  original_url: string
  url_expires_at: string
  created_at: string | null
}

export interface Project {
  id: string
  title: string
  product_name: string
  product_description: string
  status: ProjectStatus
  image_count?: number
  cover_thumbnail_url?: string | null
  images?: ProjectImage[]
  created_at: string | null
  updated_at: string | null
}

/** What the server accepts; sent with the project so the browser can check before uploading. */
export interface UploadLimits {
  max_bytes: number
  max_images: number
  formats: string[]
  min_dimension: number
  max_dimension: number
  max_title_length: number
  max_description_length: number
}

export interface ProjectEnvelope {
  data: Project
  meta?: { limits?: UploadLimits }
}

export interface ProjectPage {
  data: Project[]
  meta: { current_page: number; last_page: number; total: number }
}

export const fetchProjects = (page: number) => apiFetch<ProjectPage>(`/v1/projects?page=${page}`)

export const fetchProject = (id: string) => apiFetch<ProjectEnvelope>(`/v1/projects/${id}`)

export const createProject = (input: { title: string; product_description?: string }) =>
  apiFetch<ProjectEnvelope>('/v1/projects', { method: 'POST', json: input })

export const saveProject = (input: {
  id: string
  title?: string
  product_description?: string
}) => {
  const { id, ...fields } = input
  return apiFetch<ProjectEnvelope>(`/v1/projects/${id}`, { method: 'PATCH', json: fields })
}

export const deleteProject = (id: string) =>
  apiFetch<null>(`/v1/projects/${id}`, { method: 'DELETE', timeoutMs: 30_000 })

export const deleteImage = (input: { projectId: string; imageId: string }) =>
  apiFetch<null>(`/v1/projects/${input.projectId}/images/${input.imageId}`, {
    method: 'DELETE',
    timeoutMs: 30_000,
  })

export const reorderImages = (input: { projectId: string; order: string[] }) =>
  apiFetch<{ data: ProjectImage[] }>(`/v1/projects/${input.projectId}/images/order`, {
    method: 'PUT',
    json: { order: input.order },
  }).then((body) => body.data)

interface UploadOptions {
  onProgress?: (fraction: number) => void
  signal?: AbortSignal
}

const UPLOAD_TIMEOUT_MS = 120_000

/**
 * Uploads one image as multipart form data. Uses XMLHttpRequest because `fetch` cannot report
 * upload progress. Sends the same cookies and CSRF header as `apiFetch`, and retries once with a
 * fresh CSRF token if the server answers 419.
 */
export async function uploadImage(
  projectId: string,
  file: File,
  options: UploadOptions = {},
): Promise<ProjectImage> {
  try {
    return await sendUpload(projectId, file, options, await mutatingHeaders())
  } catch (error) {
    if (error instanceof ApiError && error.status === 419) {
      return sendUpload(projectId, file, options, await mutatingHeaders(true))
    }
    throw error
  }
}

function parseJson(text: string): unknown {
  if (!text) return null
  try {
    return JSON.parse(text) as unknown
  } catch {
    return null
  }
}

function sendUpload(
  projectId: string,
  file: File,
  { onProgress, signal }: UploadOptions,
  headers: Record<string, string>,
): Promise<ProjectImage> {
  return new Promise((resolve, reject) => {
    const xhr = new XMLHttpRequest()
    xhr.open('POST', apiUrl(`/v1/projects/${projectId}/images`))
    xhr.withCredentials = true
    xhr.timeout = UPLOAD_TIMEOUT_MS
    for (const [name, value] of Object.entries(headers)) xhr.setRequestHeader(name, value)

    const fail = (code: string, message: string) =>
      reject(new ApiError({ status: 0, code, message }))

    xhr.upload.onprogress = (event) => {
      if (event.lengthComputable && event.total > 0) onProgress?.(event.loaded / event.total)
    }
    xhr.onload = () => {
      const body = parseJson(xhr.responseText)
      if (xhr.status >= 200 && xhr.status < 300) {
        onProgress?.(1)
        resolve((body as { data: ProjectImage }).data)
      } else {
        reject(problemToError(xhr.status, body, xhr.getResponseHeader('X-Request-Id')))
      }
    }
    xhr.onerror = () => fail('network_error', 'The upload was interrupted.')
    xhr.ontimeout = () => fail('timeout', 'The upload took too long.')
    xhr.onabort = () => fail('aborted', 'The upload was cancelled.')

    const onAbort = () => xhr.abort()
    if (signal?.aborted) {
      fail('aborted', 'The upload was cancelled.')
      return
    }
    signal?.addEventListener('abort', onAbort, { once: true })

    const form = new FormData()
    form.append('image', file)
    xhr.send(form)
  })
}
