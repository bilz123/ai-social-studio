import {
  AlertCircle,
  ChevronLeft,
  ChevronRight,
  ImageOff,
  RefreshCw,
  Trash2,
  X,
} from 'lucide-react'
import { useState } from 'react'
import type { DragEvent } from 'react'
import { cn } from '@/shared/lib/cn'
import { Button } from '@/shared/ui/Button'
import type { ProjectImage } from '../api/projects'
import { formatBytes } from '../imageRules'
import type { UploadItem } from '../useImageUploads'

interface DragProps {
  draggable: boolean
  dragging: boolean
  over: boolean
  onDragStart: (event: DragEvent) => void
  onDragOver: (event: DragEvent) => void
  onDrop: (event: DragEvent) => void
  onDragEnd: () => void
}

const frame = (over = false, dragging = false) =>
  cn(
    'relative overflow-hidden rounded-xl border bg-raised transition',
    over ? 'border-brand ring-2 ring-brand' : 'border-line',
    dragging && 'opacity-50',
  )

/** A stored image: preview, position, reorder buttons and a confirmed remove. */
export function ImageTile({
  image,
  index,
  total,
  canReorder,
  removing,
  drag,
  onMove,
  onRemove,
  onPreviewError,
}: {
  image: ProjectImage
  index: number
  total: number
  canReorder: boolean
  removing: boolean
  drag: DragProps
  onMove: (direction: -1 | 1) => void
  onRemove: () => void
  onPreviewError: () => void
}) {
  const [confirming, setConfirming] = useState(false)
  // The link that failed to load; a fresh link (after the project is refetched) gets a new try.
  const [brokenUrl, setBrokenUrl] = useState<string | null>(null)
  const broken = brokenUrl !== null && brokenUrl === image.thumbnail_url
  const label = `${image.name}, image ${index + 1} of ${total}`

  return (
    <li
      aria-label={label}
      draggable={drag.draggable}
      onDragStart={drag.onDragStart}
      onDragOver={drag.onDragOver}
      onDrop={drag.onDrop}
      onDragEnd={drag.onDragEnd}
      className={cn(frame(drag.over, drag.dragging), drag.draggable && 'cursor-grab')}
    >
      <div className="relative aspect-square bg-sunken">
        {image.thumbnail_url && !broken ? (
          <img
            src={image.thumbnail_url}
            alt={`Preview of ${image.name}`}
            draggable={false}
            onError={() => {
              setBrokenUrl(image.thumbnail_url)
              onPreviewError()
            }}
            className="size-full object-cover"
          />
        ) : (
          <div className="grid size-full place-items-center text-muted">
            <ImageOff aria-hidden="true" className="size-6" />
            <span className="sr-only">Preview unavailable</span>
          </div>
        )}
        <span className="absolute top-2 left-2 rounded-full bg-black/55 px-2 py-0.5 text-xs font-semibold text-white">
          {index === 0 ? 'Cover' : index + 1}
        </span>

        {confirming && (
          <div
            role="group"
            aria-label={`Confirm removing ${image.name}`}
            className="absolute inset-0 flex flex-col items-center justify-center gap-2 bg-black/70 p-3 text-center text-white"
          >
            <p className="text-sm font-semibold">Remove this image?</p>
            <p className="text-xs text-white/90">The uploaded file is deleted.</p>
            <div className="flex gap-2">
              <Button
                variant="danger"
                size="sm"
                loading={removing}
                onClick={() => {
                  onRemove()
                  setConfirming(false)
                }}
              >
                Remove
              </Button>
              <Button variant="secondary" size="sm" onClick={() => setConfirming(false)}>
                Keep
              </Button>
            </div>
          </div>
        )}
      </div>

      <div className="px-3 pt-2">
        <p className="truncate text-sm font-medium" title={image.name}>
          {image.name}
        </p>
        <p className="truncate text-xs text-muted">
          {[
            image.width && image.height ? `${image.width}×${image.height}` : null,
            formatBytes(image.bytes),
          ]
            .filter(Boolean)
            .join(' · ')}
        </p>
      </div>

      <div className="flex items-center gap-1 p-2">
        <Button
          variant="ghost"
          size="sm"
          className="px-2"
          aria-label={`Move ${image.name} earlier`}
          disabled={!canReorder || index === 0}
          onClick={() => onMove(-1)}
        >
          <ChevronLeft aria-hidden="true" className="size-4" />
        </Button>
        <Button
          variant="ghost"
          size="sm"
          className="px-2"
          aria-label={`Move ${image.name} later`}
          disabled={!canReorder || index === total - 1}
          onClick={() => onMove(1)}
        >
          <ChevronRight aria-hidden="true" className="size-4" />
        </Button>
        <Button
          variant="ghost"
          size="sm"
          className="ml-auto px-2"
          aria-label={`Remove ${image.name}`}
          onClick={() => setConfirming(true)}
        >
          <Trash2 aria-hidden="true" className="size-4" />
        </Button>
      </div>
    </li>
  )
}

/** A file still on its way (or failed): local preview, progress, retry and cancel. */
export function UploadTile({
  item,
  onRetry,
  onCancel,
}: {
  item: UploadItem
  onRetry: () => void
  onCancel: () => void
}) {
  const percent = Math.round(item.progress * 100)
  const failed = item.status === 'failed'
  const name = item.file.name

  return (
    <li aria-label={`${name}, ${failed ? 'upload failed' : 'uploading'}`} className={frame()}>
      <div className="relative aspect-square bg-sunken">
        {item.previewUrl && (
          <img
            src={item.previewUrl}
            alt=""
            draggable={false}
            className={cn('size-full object-cover', !failed && 'opacity-60')}
          />
        )}
        {failed && (
          <div className="absolute inset-0 grid place-items-center bg-danger-bg/90 p-3 text-center">
            <div>
              <AlertCircle aria-hidden="true" className="mx-auto size-5 text-danger" />
              <p role="alert" className="mt-1 text-xs font-medium text-danger">
                {item.error}
              </p>
            </div>
          </div>
        )}
      </div>

      <div className="px-3 pt-2">
        <p className="truncate text-sm font-medium" title={name}>
          {name}
        </p>
        {failed ? (
          <p className="text-xs text-danger">Not uploaded</p>
        ) : (
          <>
            <div
              role="progressbar"
              aria-label={`Uploading ${name}`}
              aria-valuemin={0}
              aria-valuemax={100}
              aria-valuenow={item.status === 'queued' ? 0 : percent}
              className="mt-1.5 h-1.5 overflow-hidden rounded-full bg-sunken"
            >
              <div
                className="h-full rounded-full bg-brand transition-[width]"
                style={{ width: `${item.status === 'queued' ? 0 : percent}%` }}
              />
            </div>
            <p className="mt-1 text-xs text-muted">
              {item.status === 'queued' ? 'Waiting…' : `${percent}%`}
            </p>
          </>
        )}
      </div>

      <div className="flex items-center gap-1 p-2">
        {failed && (
          <Button variant="secondary" size="sm" onClick={onRetry} aria-label={`Retry ${name}`}>
            <RefreshCw aria-hidden="true" className="size-4" />
            Retry
          </Button>
        )}
        <Button
          variant="ghost"
          size="sm"
          className="ml-auto px-2"
          aria-label={failed ? `Dismiss ${name}` : `Cancel upload of ${name}`}
          onClick={onCancel}
        >
          <X aria-hidden="true" className="size-4" />
        </Button>
      </div>
    </li>
  )
}
