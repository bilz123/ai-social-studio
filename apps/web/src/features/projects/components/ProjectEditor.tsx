import { useQueryClient } from '@tanstack/react-query'
import { ArrowLeft, Check, Trash2 } from 'lucide-react'
import { useEffect, useRef, useState } from 'react'
import type { FormEvent } from 'react'
import { Link } from 'react-router-dom'
import { ApiError } from '@/shared/lib/apiClient'
import { timeAgo } from '@/shared/lib/time'
import { PageHeader } from '@/shared/layout/PageHeader'
import { Alert } from '@/shared/ui/Alert'
import { Badge } from '@/shared/ui/Badge'
import { Button } from '@/shared/ui/Button'
import { Card, CardHeader } from '@/shared/ui/Card'
import { TextField } from '@/shared/ui/TextField'
import type { Project, UploadLimits } from '../api/projects'
import { useImageUploads } from '../useImageUploads'
import {
  projectKeys,
  useDeleteImage,
  useDeleteProject,
  useReorderImages,
  useSaveProject,
} from '../useProjects'
import { DescriptionEditor } from './DescriptionEditor'
import { ImageDropzone } from './ImageDropzone'
import { ImageGrid } from './ImageGrid'

/**
 * The Create Video editor for one draft: name, product images and description.
 * The name and description are saved with "Save draft"; images are stored the moment they finish
 * uploading, and reordering or removing one is saved immediately as well.
 */
export function ProjectEditor({ project, limits }: { project: Project; limits: UploadLimits }) {
  const queryClient = useQueryClient()
  const save = useSaveProject()
  const remove = useDeleteProject()
  const removeImage = useDeleteImage(project.id)
  const reorder = useReorderImages(project.id)

  const images = project.images ?? []
  const uploads = useImageUploads({ projectId: project.id, limits, savedCount: images.length })

  const [title, setTitle] = useState(project.title)
  const [description, setDescription] = useState(project.product_description)
  const [titleError, setTitleError] = useState<string | null>(null)
  const [confirmingDelete, setConfirmingDelete] = useState(false)

  const dirty = title.trim() !== project.title || description.trim() !== project.product_description
  const leaving = dirty || uploads.busy

  // Closing the tab or reloading with unsaved text or a running upload asks first.
  useEffect(() => {
    if (!leaving) return
    const warn = (event: BeforeUnloadEvent) => {
      event.preventDefault()
    }
    window.addEventListener('beforeunload', warn)
    return () => window.removeEventListener('beforeunload', warn)
  }, [leaving])

  // A picture dropped just outside the upload area would make the browser open it and throw the
  // page (and any unsaved text) away. Swallow file drops everywhere else on the page.
  useEffect(() => {
    const isFiles = (event: DragEvent) =>
      Array.from(event.dataTransfer?.types ?? []).includes('Files')
    const stop = (event: DragEvent) => {
      if (isFiles(event)) event.preventDefault()
    }
    window.addEventListener('dragover', stop)
    window.addEventListener('drop', stop)
    return () => {
      window.removeEventListener('dragover', stop)
      window.removeEventListener('drop', stop)
    }
  }, [])

  // Signed image links expire; when one fails to load, fetch fresh ones (at most twice a minute).
  const lastRefresh = useRef(0)
  const refreshLinks = () => {
    const now = Date.now()
    if (now - lastRefresh.current < 30_000) return
    lastRefresh.current = now
    void queryClient.invalidateQueries({ queryKey: projectKeys.detail(project.id) })
  }

  const submit = (event: FormEvent) => {
    event.preventDefault()
    const name = title.trim()
    if (!name) {
      setTitleError('Give the project a name.')
      return
    }
    setTitleError(null)
    save.mutate(
      { id: project.id, title: name, product_description: description.trim() },
      {
        onError: (error) => {
          if (error instanceof ApiError && error.errors.title?.[0]) {
            setTitleError(error.errors.title[0])
          }
        },
      },
    )
  }

  const full = images.length + uploads.items.length >= limits.max_images

  return (
    <form onSubmit={submit} noValidate>
      <Link
        to="/create"
        className="mb-4 inline-flex min-h-9 items-center gap-1.5 text-sm font-medium text-muted hover:text-ink"
      >
        <ArrowLeft aria-hidden="true" className="size-4" />
        All projects
      </Link>

      <PageHeader
        title={project.title || 'Untitled project'}
        description={`Add product photos and describe the product. Updated ${
          project.updated_at ? timeAgo(project.updated_at) : 'just now'
        }.`}
        actions={<Badge tone="neutral">Draft</Badge>}
      />

      <div className="space-y-6">
        <Card aria-labelledby="project-name-heading">
          <CardHeader id="project-name-heading" title="Project" />
          <div className="p-5">
            <TextField
              label="Project name"
              value={title}
              maxLength={limits.max_title_length}
              error={titleError ?? undefined}
              hint="Only you see this. Pick something you will recognise."
              onChange={(event) => {
                setTitle(event.target.value)
                if (titleError) setTitleError(null)
              }}
            />
          </div>
        </Card>

        <Card aria-labelledby="images-heading">
          <CardHeader
            id="images-heading"
            title="Product images"
            description="The first image is the cover. Drag images, or use the arrow buttons, to change the order."
            action={
              <Badge tone={full ? 'warn' : 'neutral'}>
                {images.length} / {limits.max_images}
              </Badge>
            }
          />
          <div className="space-y-4 p-5">
            <ImageDropzone
              limits={limits}
              full={full}
              onFiles={(files) => void uploads.addFiles(files)}
            />

            {uploads.notices.length > 0 && (
              <Alert tone="error">
                <div className="flex items-start justify-between gap-3">
                  <ul className="space-y-1">
                    {uploads.notices.map((notice) => (
                      <li key={notice}>{notice}</li>
                    ))}
                  </ul>
                  <button
                    type="button"
                    onClick={uploads.dismissNotices}
                    className="shrink-0 rounded px-1 underline underline-offset-4"
                  >
                    Dismiss
                  </button>
                </div>
              </Alert>
            )}

            {images.length + uploads.items.length > 0 ? (
              <ImageGrid
                images={images}
                uploads={uploads.items}
                canReorder={!uploads.busy && !reorder.isPending}
                removingId={removeImage.isPending ? (removeImage.variables ?? null) : null}
                onReorder={(order) => reorder.mutate(order)}
                onRemove={(image) => removeImage.mutate(image.id)}
                onRetry={uploads.retry}
                onCancel={uploads.cancel}
                onPreviewError={refreshLinks}
              />
            ) : (
              <p className="text-sm text-muted">No images yet.</p>
            )}

            <p className="text-sm text-muted">
              Images are stored privately and saved to this project as soon as they finish
              uploading. Your originals are never changed.
            </p>
          </div>
        </Card>

        <Card aria-labelledby="description-heading">
          <CardHeader id="description-heading" title="Description" />
          <div className="p-5">
            <DescriptionEditor
              value={description}
              maxLength={limits.max_description_length}
              onChange={setDescription}
            />
          </div>
        </Card>

        <Card aria-labelledby="delete-heading">
          <CardHeader id="delete-heading" title="Delete project" />
          <div className="p-5">
            {confirmingDelete ? (
              <div
                role="group"
                aria-label="Confirm deleting this project"
                className="rounded-lg bg-danger-bg p-4"
              >
                <p className="text-sm font-medium text-danger">Delete “{project.title}”?</p>
                <p className="mt-1 text-sm text-ink">
                  The project, its description and every uploaded image are permanently deleted.
                </p>
                <div className="mt-3 flex flex-wrap gap-2">
                  <Button
                    variant="danger"
                    size="sm"
                    loading={remove.isPending}
                    onClick={() => remove.mutate(project.id)}
                  >
                    Delete project
                  </Button>
                  <Button variant="secondary" size="sm" onClick={() => setConfirmingDelete(false)}>
                    Cancel
                  </Button>
                </div>
              </div>
            ) : (
              <div className="flex flex-wrap items-center justify-between gap-3">
                <p className="max-w-prose text-sm text-muted">
                  Removes the project and every image you uploaded to it. This cannot be undone.
                </p>
                <Button variant="secondary" size="sm" onClick={() => setConfirmingDelete(true)}>
                  <Trash2 aria-hidden="true" className="size-4" />
                  Delete project
                </Button>
              </div>
            )}
          </div>
        </Card>
      </div>

      <div className="sticky bottom-0 z-10 -mx-4 mt-6 flex flex-wrap items-center justify-between gap-3 border-t border-line bg-surface/90 px-4 py-3 backdrop-blur sm:mx-0 sm:rounded-xl sm:border sm:px-5">
        <p role="status" className="flex items-center gap-2 text-sm text-muted">
          {uploads.busy ? (
            'Uploading images…'
          ) : dirty ? (
            <span className="font-medium text-warn">Unsaved changes</span>
          ) : (
            <>
              <Check aria-hidden="true" className="size-4 text-ok" />
              All changes saved
            </>
          )}
        </p>
        <Button type="submit" loading={save.isPending} disabled={!dirty}>
          Save draft
        </Button>
      </div>
    </form>
  )
}
