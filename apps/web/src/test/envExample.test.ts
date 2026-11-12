import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const SECRET_HINTS = ['SECRET', 'PASSWORD', 'PASSWD', 'TOKEN', 'PRIVATE', 'CREDENTIAL', 'API_KEY']

function envKeys(file: string): string[] {
  return readFileSync(resolve(process.cwd(), file), 'utf8')
    .split('\n')
    .map((line) => line.trim())
    .filter((line) => line && !line.startsWith('#') && line.includes('='))
    .map((line) => line.slice(0, line.indexOf('=')))
}

describe('.env.example (browser-visible configuration)', () => {
  const keys = envKeys('.env.example')

  it('only declares public VITE_* variables (plus the dev-only proxy target)', () => {
    const unexpected = keys.filter(
      (key) => !key.startsWith('VITE_') && key !== 'DEV_API_PROXY_TARGET',
    )
    expect(unexpected).toEqual([])
  })

  it('has no variable name that looks like a secret', () => {
    const suspicious = keys.filter((key) => SECRET_HINTS.some((hint) => key.includes(hint)))
    expect(suspicious).toEqual([])
  })
})
