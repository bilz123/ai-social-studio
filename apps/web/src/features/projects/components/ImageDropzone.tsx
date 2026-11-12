import { ImagePlus } from 'lucide-react'
import { useId, useRef, useState } from 'react'
import type { DragEvent } from 'react'
import { cn } from '@/shared/lib/cn'
import { Button } from '@/shared/ui/Button'
import { formatBytes } from '../imageRules'
import type { UploadLimits } from '../api/projects'

const isFileDrag = (event: DragEvent) =>
  Array.from(event.dataTransfer?.types ?? []).includes('Files')

/** Drop files here or choose them with the button; keyboard and screen reader friendly. */
export function ImageDropzone({
  limits,
  disabled,
  full,
  onFiles,
}: {
  limits: UploadLimits
  disabled?: boolean
  /** The project already holds the most images it can. */
  full: boolean
  onFiles: (files: File[]) => void
}) {
  const inputRef = useRef<HTMLInputElement>(null)
  const hintId = useId()
  const [dragging, setDragging] = useState(false)
  const inactive = disabled || full

  const choose = (list: FileList | null) => {
    const files = Array.from(list ?? [])
    if (files.length > 0) onFiles(files)
  }

  return (
    <div
      role="group"
      aria-label="Image upload area"
      aria-describedby={hintId}
      onDragEnter={(event) => {
        if (!inactive && isFileDrag(event)) {
          event.preventDefault()
          setDragging(true)
        }
      }}
      onDragOver={(event) => {
        if (isFileDrag(event)) {
          // Always claim file drags so a miss never makes the browser open the picture.
          event.preventDefault()
          if (!inactive) setDragging(true)
        }
      }}
      onDragLeave={(event) => {
        if (!event.currentTarget.contains(event.relatedTarget as Node | null)) setDragging(false)
      }}
      onDrop={(event) => {
        if (!isFileDrag(event)) return
        event.preventDefault()
        setDragging(false)
        if (!inactive) choose(event.dataTransfer.files)
      }}
      className={cn(
        'flex flex-col items-center rounded-xl border-2 border-dashed px-6 py-8 text-center transition-colors',
        dragging ? 'border-brand bg-info-bg' : 'border-line bg-sunken',
        inactive && 'opacity-70',
      )}
    >
      <span className="grid size-11 place-items-center rounded-full bg-raised text-muted">
        <ImagePlus aria-hidden="true" className="size-5" />
      </span>
      <p className="mt-3 font-display text-base font-semibold">
        {dragging ? 'Drop to add these images' : 'Drag product photos here'}
      </p>
      <p id={hintId} className="mt-1 max-w-md text-sm text-muted">
        JPEG, PNG or WebP, up to {formatBytes(limits.max_bytes)} each, at least{' '}
        {limits.min_dimension} px on the short side. Up to {limits.max_images} images per project.
      </p>
      <input
        ref={inputRef}
        type="file"
        multiple
        accept={limits.formats.join(',')}
        aria-label="Choose images"
        className="sr-only"
        tabIndex={-1}
        disabled={inactive}
        onChange={(event) => {
          choose(event.target.files)
          // Lets the same file be chosen again after it was removed.
          event.target.value = ''
        }}
      />
      <Button
        variant="secondary"
        className="mt-4"
        disabled={inactive}
        onClick={() => inputRef.current?.click()}
      >
        {full ? 'Image limit reached' : 'Choose images'}
      </Button>
    </div>
  )
}
