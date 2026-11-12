/**
 * A stand-in for XMLHttpRequest so upload tests can drive progress and responses by hand.
 * Install with `vi.stubGlobal('XMLHttpRequest', FakeXhr)`.
 */
export class FakeXhr {
  static instances: FakeXhr[] = []

  static reset() {
    FakeXhr.instances = []
  }

  method = ''
  url = ''
  headers: Record<string, string> = {}
  withCredentials = false
  timeout = 0
  status = 0
  responseText = ''
  body: FormData | null = null
  aborted = false

  upload: {
    onprogress:
      ((event: { lengthComputable: boolean; loaded: number; total: number }) => void) | null
  } = { onprogress: null }
  onload: (() => void) | null = null
  onerror: (() => void) | null = null
  ontimeout: (() => void) | null = null
  onabort: (() => void) | null = null

  constructor() {
    FakeXhr.instances.push(this)
  }

  open(method: string, url: string) {
    this.method = method
    this.url = url
  }

  setRequestHeader(name: string, value: string) {
    this.headers[name] = value
  }

  send(body: FormData) {
    this.body = body
  }

  abort() {
    this.aborted = true
    this.onabort?.()
  }

  getResponseHeader() {
    return null
  }

  /** Test helper: report upload progress. */
  progress(loaded: number, total: number) {
    this.upload.onprogress?.({ lengthComputable: true, loaded, total })
  }

  /** Test helper: finish with a JSON response. */
  respond(status: number, body: unknown) {
    this.status = status
    this.responseText = JSON.stringify(body)
    this.onload?.()
  }

  /** Test helper: simulate a dropped connection. */
  fail() {
    this.onerror?.()
  }
}
