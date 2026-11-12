import type { UploadLimits } from './api/projects'

export const ACCEPTED_TYPES = ['image/jpeg', 'image/png', 'image/webp'] as const
export type AcceptedType = (typeof ACCEPTED_TYPES)[number]

/** Used until the server's limits arrive; the server is the authority and always re-checks. */
export const DEFAULT_LIMITS: UploadLimits = {
  max_bytes: 10 * 1024 * 1024,
  max_images: 10,
  formats: [...ACCEPTED_TYPES],
  min_dimension: 256,
  max_dimension: 8000,
  max_title_length: 160,
  max_description_length: 2000,
}

export function formatBytes(bytes: number): string {
  if (bytes < 1024) return `${bytes} B`
  if (bytes < 1024 * 1024) return `${Math.round(bytes / 1024)} KB`
  const megabytes = bytes / (1024 * 1024)
  return `${megabytes >= 10 ? Math.round(megabytes) : megabytes.toFixed(1)} MB`
}

/** The picture type according to the file's first bytes, or null when it is none of the three. */
export function sniffImageType(bytes: Uint8Array): AcceptedType | null {
  const startsWith = (signature: number[], offset = 0) =>
    signature.every((value, index) => bytes[offset + index] === value)

  if (startsWith([0xff, 0xd8, 0xff])) return 'image/jpeg'
  if (startsWith([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a])) return 'image/png'
  // WebP is a RIFF container: "RIFF" + 4 size bytes + "WEBP".
  if (startsWith([0x52, 0x49, 0x46, 0x46]) && startsWith([0x57, 0x45, 0x42, 0x50], 8)) {
    return 'image/webp'
  }
  return null
}

function readHeader(file: Blob): Promise<Uint8Array> {
  return new Promise((resolve, reject) => {
    const reader = new FileReader()
    reader.onerror = () => reject(new Error('The file could not be read.'))
    reader.onload = () => resolve(new Uint8Array(reader.result as ArrayBuffer))
    reader.readAsArrayBuffer(file.slice(0, 16))
  })
}

/**
 * Quick checks before anything is uploaded. The type comes from the file's own bytes, not from
 * its name or the browser's guess, so a renamed document is caught here. The server repeats every
 * check (and decodes the picture); this only saves the person a slow upload that would fail.
 * Returns a message for the person, or null when the file looks fine.
 */
export async function checkFile(
  file: File,
  limits: Pick<UploadLimits, 'max_bytes' | 'formats'>,
): Promise<string | null> {
  if (file.size === 0) return `${file.name} is empty.`
  if (file.size > limits.max_bytes) {
    return `${file.name} is larger than ${formatBytes(limits.max_bytes)}.`
  }

  const type = await readHeader(file).then(sniffImageType, () => undefined)
  if (type === undefined) return `${file.name} could not be read.`

  if (type === null || !limits.formats.includes(type)) {
    return `${file.name} is not a JPEG, PNG or WebP image.`
  }
  return null
}

/** Moves one entry to a new place; the others shift to make room (like moving a card). */
export function moveItem<T>(list: readonly T[], from: number, to: number): T[] {
  if (from === to || from < 0 || to < 0 || from >= list.length || to >= list.length) {
    return [...list]
  }
  const copy = [...list]
  const [moved] = copy.splice(from, 1)
  copy.splice(to, 0, moved as T)
  return copy
}
