import { useState } from 'react'
import type { ProjectImage } from '../api/projects'
import { moveItem } from '../imageRules'
import type { UploadItem } from '../useImageUploads'
import { ImageTile, UploadTile } from './ImageTile'

/**
 * Stored images (reorderable by drag and drop or by the arrow buttons) followed by files that are
 * still uploading. Reordering is switched off while uploads run so the saved order cannot change
 * underneath the person.
 */
export function ImageGrid({
  images,
  uploads,
  canReorder,
  removingId,
  onReorder,
  onRemove,
  onRetry,
  onCancel,
  onPreviewError,
}: {
  images: ProjectImage[]
  uploads: UploadItem[]
  canReorder: boolean
  removingId: string | null
  onReorder: (order: string[]) => void
  onRemove: (image: ProjectImage) => void
  onRetry: (id: string) => void
  onCancel: (id: string) => void
  onPreviewError: () => void
}) {
  const [dragId, setDragId] = useState<string | null>(null)
  const [overId, setOverId] = useState<string | null>(null)

  const ids = images.map((image) => image.id)

  const reorder = (from: number, to: number) => {
    if (from === to) return
    onReorder(moveItem(ids, from, to))
  }

  const endDrag = () => {
    setDragId(null)
    setOverId(null)
  }

  return (
    <ul className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
      {images.map((image, index) => (
        <ImageTile
          key={image.id}
          image={image}
          index={index}
          total={images.length}
          canReorder={canReorder}
          removing={removingId === image.id}
          onMove={(direction) => reorder(index, index + direction)}
          onRemove={() => onRemove(image)}
          onPreviewError={onPreviewError}
          drag={{
            draggable: canReorder && images.length > 1,
            dragging: dragId === image.id,
            over: overId === image.id && dragId !== null && dragId !== image.id,
            onDragStart: (event) => {
              setDragId(image.id)
              if (event.dataTransfer) {
                event.dataTransfer.effectAllowed = 'move'
                // Firefox only starts a drag when some data is set.
                event.dataTransfer.setData('text/plain', image.id)
              }
            },
            onDragOver: (event) => {
              if (dragId === null) return // a file drag: the upload area handles it
              event.preventDefault()
              if (event.dataTransfer) event.dataTransfer.dropEffect = 'move'
              setOverId(image.id)
            },
            onDrop: (event) => {
              if (dragId === null) return
              event.preventDefault()
              reorder(ids.indexOf(dragId), index)
              endDrag()
            },
            onDragEnd: endDrag,
          }}
        />
      ))}
      {uploads.map((item) => (
        <UploadTile
          key={item.id}
          item={item}
          onRetry={() => onRetry(item.id)}
          onCancel={() => onCancel(item.id)}
        />
      ))}
    </ul>
  )
}
