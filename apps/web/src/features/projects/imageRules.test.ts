import { describe, expect, it } from 'vitest'
import { DEFAULT_LIMITS, checkFile, formatBytes, moveItem, sniffImageType } from './imageRules'

const bytes = (...values: number[]) => new Uint8Array([...values, ...new Array<number>(16).fill(0)])
const JPEG = [0xff, 0xd8, 0xff, 0xe0]
const PNG = [0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a]
const WEBP = [0x52, 0x49, 0x46, 0x46, 1, 2, 3, 4, 0x57, 0x45, 0x42, 0x50]
const GIF = [0x47, 0x49, 0x46, 0x38, 0x39, 0x61]

const file = (content: number[], name: string, type = 'image/jpeg') =>
  new File([bytes(...content)], name, { type })

describe('sniffImageType', () => {
  it('recognises JPEG, PNG and WebP from their first bytes', () => {
    expect(sniffImageType(bytes(...JPEG))).toBe('image/jpeg')
    expect(sniffImageType(bytes(...PNG))).toBe('image/png')
    expect(sniffImageType(bytes(...WEBP))).toBe('image/webp')
  })

  it('rejects everything else, including other RIFF files and look-alikes', () => {
    expect(sniffImageType(bytes(...GIF))).toBeNull()
    expect(
      sniffImageType(new TextEncoder().encode('<svg xmlns="http://www.w3.org/2000/svg">')),
    ).toBeNull()
    expect(sniffImageType(new TextEncoder().encode('%PDF-1.4 hello world'))).toBeNull()
    // A WAV file is RIFF too, but not WEBP.
    expect(
      sniffImageType(bytes(0x52, 0x49, 0x46, 0x46, 1, 2, 3, 4, 0x57, 0x41, 0x56, 0x45)),
    ).toBeNull()
    expect(sniffImageType(new Uint8Array())).toBeNull()
  })
})

describe('checkFile', () => {
  it('accepts real pictures regardless of their name or declared type', async () => {
    expect(await checkFile(file(JPEG, 'a.jpg'), DEFAULT_LIMITS)).toBeNull()
    expect(await checkFile(file(PNG, 'a.png', 'image/png'), DEFAULT_LIMITS)).toBeNull()
    expect(await checkFile(file(WEBP, 'a.webp', 'image/webp'), DEFAULT_LIMITS)).toBeNull()
    // Wrong extension and declared type, right content.
    expect(await checkFile(file(PNG, 'holiday.txt', 'text/plain'), DEFAULT_LIMITS)).toBeNull()
  })

  it('rejects a renamed non-image even when it claims to be a JPEG', async () => {
    const message = await checkFile(file(GIF, 'cat.jpg', 'image/jpeg'), DEFAULT_LIMITS)
    expect(message).toBe('cat.jpg is not a JPEG, PNG or WebP image.')
  })

  it('rejects empty and oversized files with the limit in the message', async () => {
    expect(await checkFile(new File([], 'empty.jpg'), DEFAULT_LIMITS)).toBe('empty.jpg is empty.')

    const big = file(JPEG, 'big.jpg')
    Object.defineProperty(big, 'size', { value: 11 * 1024 * 1024 })
    expect(await checkFile(big, DEFAULT_LIMITS)).toBe('big.jpg is larger than 10 MB.')
  })

  it('respects the formats the server allows', async () => {
    const limits = { ...DEFAULT_LIMITS, formats: ['image/jpeg'] }
    expect(await checkFile(file(PNG, 'a.png', 'image/png'), limits)).toContain('is not a JPEG')
  })
})

describe('helpers', () => {
  it('formats sizes', () => {
    expect(formatBytes(512)).toBe('512 B')
    expect(formatBytes(2048)).toBe('2 KB')
    expect(formatBytes(1.5 * 1024 * 1024)).toBe('1.5 MB')
    expect(formatBytes(10 * 1024 * 1024)).toBe('10 MB')
  })

  it('moves an item and shifts the others', () => {
    expect(moveItem(['a', 'b', 'c', 'd'], 0, 2)).toEqual(['b', 'c', 'a', 'd'])
    expect(moveItem(['a', 'b', 'c', 'd'], 3, 0)).toEqual(['d', 'a', 'b', 'c'])
    expect(moveItem(['a', 'b'], 1, 1)).toEqual(['a', 'b'])
    expect(moveItem(['a', 'b'], 0, 5)).toEqual(['a', 'b'])
  })
})
