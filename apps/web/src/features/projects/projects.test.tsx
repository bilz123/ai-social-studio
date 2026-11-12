import { fireEvent, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { FakeXhr } from '@/test/fakeXhr'
import { jsonResponse, mockApi, renderApp, signedInHandlers } from '@/test/renderApp'
import type { Handler } from '@/test/renderApp'
import type { Project, ProjectImage, UploadLimits } from './api/projects'
import { DEFAULT_LIMITS } from './imageRules'

const PROJECT_ID = '01PROJECT000000000000000001'
const base = `/v1/projects/${PROJECT_ID}`

beforeEach(() => {
  // Present, so the API client does not first fetch a CSRF cookie before state-changing requests.
  document.cookie = 'XSRF-TOKEN=token'
  FakeXhr.reset()
  vi.stubGlobal('XMLHttpRequest', FakeXhr)
  URL.createObjectURL = vi.fn(() => 'blob:preview')
  URL.revokeObjectURL = vi.fn()
})
afterEach(() => {
  vi.unstubAllGlobals()
  document.cookie = 'XSRF-TOKEN=; expires=Thu, 01 Jan 1970 00:00:00 GMT'
})

const image = (n: number, overrides: Partial<ProjectImage> = {}): ProjectImage => ({
  id: `01IMAGE${String(n).padStart(19, '0')}`,
  name: `photo-${n}.jpg`,
  mime: 'image/jpeg',
  bytes: 250_000,
  width: 1200,
  height: 800,
  position: n,
  status: 'validated',
  thumbnail_url: `/api/v1/files/images/${n}/thumb?signature=x`,
  original_url: `/api/v1/files/images/${n}/original?signature=x`,
  url_expires_at: '2026-11-12T11:00:00Z',
  created_at: '2026-11-12T10:00:00Z',
  ...overrides,
})

const project = (images: ProjectImage[] = [], overrides: Partial<Project> = {}): Project => ({
  id: PROJECT_ID,
  title: 'Summer launch',
  product_name: 'Summer launch',
  product_description: '',
  status: 'draft',
  image_count: images.length,
  images,
  created_at: '2026-11-12T09:00:00Z',
  updated_at: '2026-11-12T10:00:00Z',
  ...overrides,
})

const envelope = (p: Project, limits: Partial<UploadLimits> = {}) => ({
  data: p,
  meta: { limits: { ...DEFAULT_LIMITS, max_images: 4, ...limits } },
})

const editorHandlers = (p: Project, limits: Partial<UploadLimits> = {}) => ({
  ...signedInHandlers(),
  [`GET ${base}`]: () => jsonResponse(envelope(p, limits)),
})

const JPEG = new Uint8Array([0xff, 0xd8, 0xff, 0xe0, ...new Array<number>(40).fill(1)])
const jpegFile = (name: string) => new File([JPEG], name, { type: 'image/jpeg' })
const textFile = (name: string) =>
  new File([new TextEncoder().encode('This is not a picture at all.')], name, {
    type: 'image/jpeg',
  })

const dropFiles = (files: File[]) =>
  fireEvent.drop(screen.getByRole('group', { name: 'Image upload area' }), {
    dataTransfer: { files, types: ['Files'] },
  })

const tileNames = () =>
  screen
    .getAllByRole('listitem', { name: /, image \d+ of \d+$/ })
    .map((tile) => /^(.*), image/.exec(tile.getAttribute('aria-label') ?? '')?.[1])

const openEditor = async (
  p: Project,
  limits: Partial<UploadLimits> = {},
  extra: Record<string, Handler> = {},
) => {
  const calls = mockApi({ ...editorHandlers(p, limits), ...extra })
  renderApp(`/create/${PROJECT_ID}`)
  await screen.findByLabelText('Project name')
  return calls
}

describe('create video page', () => {
  it('invites the person to start, then creates a draft and opens the editor', async () => {
    let created: unknown
    mockApi({
      ...signedInHandlers(),
      'POST /v1/projects': (init) => {
        created = JSON.parse(String(init?.body))
        return jsonResponse(envelope(project([], { title: 'Untitled project' })), 201)
      },
      [`GET ${base}`]: () => jsonResponse(envelope(project([], { title: 'Untitled project' }))),
    })
    renderApp('/create')

    expect(await screen.findByText('Start your first video')).toBeInTheDocument()
    await userEvent.click(screen.getAllByRole('button', { name: 'New project' })[0] as HTMLElement)

    expect(await screen.findByLabelText('Project name')).toHaveValue('Untitled project')
    expect(created).toEqual({ title: 'Untitled project' })
  })

  it('lists drafts with their cover, image count and a link to the editor', async () => {
    mockApi({
      ...signedInHandlers(),
      'GET /v1/projects?page=1': () =>
        jsonResponse({
          data: [
            project([], {
              image_count: 2,
              cover_thumbnail_url: '/api/v1/files/images/1/thumb?signature=x',
            }),
            project([], { id: '01PROJECT000000000000000002', title: 'Winter set', image_count: 0 }),
          ],
          meta: { current_page: 1, last_page: 1, total: 2 },
        }),
    })
    renderApp('/create')

    const first = await screen.findByRole('link', { name: /Summer launch/ })
    expect(first).toHaveAttribute('href', `/create/${PROJECT_ID}`)
    expect(within(first).getByText(/2 images/)).toBeInTheDocument()
    expect(first.querySelector('img')).toHaveAttribute(
      'src',
      '/api/v1/files/images/1/thumb?signature=x',
    )
    expect(
      within(screen.getByRole('link', { name: /Winter set/ })).getByText(/0 images/),
    ).toBeInTheDocument()
  })

  it('pages through more projects', async () => {
    mockApi({
      ...signedInHandlers(),
      'GET /v1/projects?page=1': () =>
        jsonResponse({ data: [project()], meta: { current_page: 1, last_page: 2, total: 13 } }),
      'GET /v1/projects?page=2': () =>
        jsonResponse({
          data: [project([], { id: '01PROJECT000000000000000002', title: 'Older project' })],
          meta: { current_page: 2, last_page: 2, total: 13 },
        }),
    })
    renderApp('/create')

    await userEvent.click(await screen.findByRole('button', { name: 'Older' }))
    expect(await screen.findByRole('link', { name: /Older project/ })).toBeInTheDocument()
    expect(screen.getByText('Page 2 of 2')).toBeInTheDocument()
  })
})

describe('project editor', () => {
  it('shows the saved project with its images in order and the cover marked', async () => {
    await openEditor(project([image(1), image(2)], { product_description: 'A ceramic mug.' }))

    expect(screen.getByLabelText('Project name')).toHaveValue('Summer launch')
    expect(screen.getByLabelText('Product description')).toHaveValue('A ceramic mug.')
    expect(tileNames()).toEqual(['photo-1.jpg', 'photo-2.jpg'])
    expect(screen.getByText('Cover')).toBeInTheDocument()
    expect(screen.getByText('2 / 4')).toBeInTheDocument()
    expect(screen.getByAltText('Preview of photo-1.jpg')).toHaveAttribute(
      'src',
      '/api/v1/files/images/1/thumb?signature=x',
    )
    expect(screen.getByText('All changes saved')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Save draft' })).toBeDisabled()
  })

  it('says so when the project does not exist', async () => {
    mockApi({
      ...signedInHandlers(),
      [`GET ${base}`]: () =>
        jsonResponse(
          { type: 'about:blank', status: 404, code: 'not_found', detail: 'Not found.' },
          404,
        ),
    })
    renderApp(`/create/${PROJECT_ID}`)

    expect(await screen.findByText('Project not found')).toBeInTheDocument()
  })

  describe('saving the draft', () => {
    it('saves the name and description and then shows everything as saved', async () => {
      let body: unknown
      await openEditor(
        project(),
        {},
        {
          [`PATCH ${base}`]: (init) => {
            body = JSON.parse(String(init?.body))
            return jsonResponse({
              data: project([], { title: 'Autumn launch', product_description: 'Warm mugs.' }),
            })
          },
        },
      )

      const name = screen.getByLabelText('Project name')
      await userEvent.clear(name)
      await userEvent.type(name, '  Autumn launch ')
      await userEvent.type(screen.getByLabelText('Product description'), 'Warm mugs.')

      expect(screen.getByText('Unsaved changes')).toBeInTheDocument()
      await userEvent.click(screen.getByRole('button', { name: 'Save draft' }))

      expect(await screen.findByText('Draft saved')).toBeInTheDocument()
      expect(body).toEqual({ title: 'Autumn launch', product_description: 'Warm mugs.' })
      await waitFor(() => expect(screen.getByText('All changes saved')).toBeInTheDocument())
      expect(screen.getByRole('button', { name: 'Save draft' })).toBeDisabled()
    })

    it('does not send a blank project name', async () => {
      const calls = await openEditor(project())

      await userEvent.clear(screen.getByLabelText('Project name'))
      await userEvent.click(screen.getByRole('button', { name: 'Save draft' }))

      expect(await screen.findByText('Give the project a name.')).toBeInTheDocument()
      expect(calls.some((call) => call.startsWith('PATCH'))).toBe(false)
    })

    it('shows the server error for a field and keeps the text', async () => {
      await openEditor(
        project(),
        {},
        {
          [`PATCH ${base}`]: () =>
            jsonResponse(
              {
                status: 422,
                code: 'validation_failed',
                detail: 'The request is invalid.',
                errors: { title: ['The title field must not be greater than 160 characters.'] },
              },
              422,
            ),
        },
      )

      await userEvent.type(screen.getByLabelText('Product description'), 'Keep me')
      await userEvent.click(screen.getByRole('button', { name: 'Save draft' }))

      expect(await screen.findByText(/must not be greater than 160/)).toBeInTheDocument()
      expect(screen.getByLabelText('Product description')).toHaveValue('Keep me')
    })

    it('counts characters and stops at the limit', async () => {
      await openEditor(project([], { product_description: 'Hello' }), {
        max_description_length: 20,
      })

      expect(screen.getByText('5 / 20')).toBeInTheDocument()
      const box = screen.getByLabelText('Product description')
      expect(box).toHaveAttribute('maxlength', '20')
      await userEvent.type(box, ' world')
      expect(screen.getByText('11 / 20')).toBeInTheDocument()
    })

    it('warns before the tab closes while there are unsaved changes', async () => {
      await openEditor(project())

      const clean = new Event('beforeunload', { cancelable: true })
      window.dispatchEvent(clean)
      expect(clean.defaultPrevented).toBe(false)

      await userEvent.type(screen.getByLabelText('Product description'), 'x')
      const dirty = new Event('beforeunload', { cancelable: true })
      window.dispatchEvent(dirty)
      expect(dirty.defaultPrevented).toBe(true)
    })
  })

  describe('uploading', () => {
    it('shows a preview and progress, then swaps in the stored image', async () => {
      await openEditor(project())

      dropFiles([jpegFile('mug.jpg')])

      const bar = await screen.findByRole('progressbar', { name: 'Uploading mug.jpg' })
      await waitFor(() => expect(FakeXhr.instances).toHaveLength(1))
      const xhr = FakeXhr.instances[0] as FakeXhr

      expect(xhr.method).toBe('POST')
      expect(xhr.url).toBe(`/api${base}/images`)
      expect(xhr.withCredentials).toBe(true)
      expect(xhr.headers['X-XSRF-TOKEN']).toBe('token')
      expect(xhr.headers.Accept).toBe('application/json')
      expect((xhr.body?.get('image') as File).name).toBe('mug.jpg')

      xhr.progress(50, 100)
      await waitFor(() => expect(bar).toHaveAttribute('aria-valuenow', '50'))
      expect(screen.getByText('50%')).toBeInTheDocument()
      expect(screen.getByText('Uploading images…')).toBeInTheDocument()

      xhr.respond(201, { data: image(1, { name: 'mug.jpg' }) })

      expect(await screen.findByAltText('Preview of mug.jpg')).toBeInTheDocument()
      expect(screen.queryByRole('progressbar')).not.toBeInTheDocument()
      expect(screen.getByText('1 / 4')).toBeInTheDocument()
      expect(screen.getByText('All changes saved')).toBeInTheDocument()
    })

    it('uploads several files one after another, in the order they were chosen', async () => {
      await openEditor(project())

      dropFiles([jpegFile('a.jpg'), jpegFile('b.jpg')])

      await waitFor(() => expect(FakeXhr.instances).toHaveLength(1))
      expect((FakeXhr.instances[0]?.body?.get('image') as File).name).toBe('a.jpg')
      expect(screen.getByText('Waiting…')).toBeInTheDocument()

      FakeXhr.instances[0]?.respond(201, { data: image(1, { name: 'a.jpg' }) })
      await waitFor(() => expect(FakeXhr.instances).toHaveLength(2))
      expect((FakeXhr.instances[1]?.body?.get('image') as File).name).toBe('b.jpg')

      FakeXhr.instances[1]?.respond(201, { data: image(2, { name: 'b.jpg' }) })
      await waitFor(() => expect(tileNames()).toEqual(['a.jpg', 'b.jpg']))
    })

    it('rejects files that are not JPEG, PNG or WebP before uploading anything', async () => {
      await openEditor(project())

      const gif = new File([new TextEncoder().encode('GIF89a......')], 'cat.gif', {
        type: 'image/gif',
      })
      const big = jpegFile('huge.jpg')
      Object.defineProperty(big, 'size', { value: 11 * 1024 * 1024 })
      dropFiles([textFile('notes.jpg'), gif, big])

      expect(
        await screen.findByText('notes.jpg is not a JPEG, PNG or WebP image.'),
      ).toBeInTheDocument()
      expect(screen.getByText('cat.gif is not a JPEG, PNG or WebP image.')).toBeInTheDocument()
      expect(screen.getByText('huge.jpg is larger than 10 MB.')).toBeInTheDocument()
      expect(FakeXhr.instances).toHaveLength(0)

      await userEvent.click(screen.getByRole('button', { name: 'Dismiss' }))
      expect(
        screen.queryByText('cat.gif is not a JPEG, PNG or WebP image.'),
      ).not.toBeInTheDocument()
    })

    it('accepts the good files from a mixed drop and reports the rest', async () => {
      await openEditor(project())

      dropFiles([textFile('notes.jpg'), jpegFile('mug.jpg')])

      expect(
        await screen.findByText('notes.jpg is not a JPEG, PNG or WebP image.'),
      ).toBeInTheDocument()
      await waitFor(() => expect(FakeXhr.instances).toHaveLength(1))
      expect((FakeXhr.instances[0]?.body?.get('image') as File).name).toBe('mug.jpg')
    })

    it('works from the Choose images button as well', async () => {
      await openEditor(project())

      await userEvent.upload(screen.getByLabelText('Choose images'), jpegFile('picked.jpg'))

      await waitFor(() => expect(FakeXhr.instances).toHaveLength(1))
      expect((FakeXhr.instances[0]?.body?.get('image') as File).name).toBe('picked.jpg')
    })

    it('shows the server message when an upload is refused, and lets the person dismiss it', async () => {
      await openEditor(project())

      dropFiles([jpegFile('dup.jpg')])
      await waitFor(() => expect(FakeXhr.instances).toHaveLength(1))
      FakeXhr.instances[0]?.respond(422, {
        status: 422,
        code: 'image_duplicate',
        detail: 'This image is already in the project.',
      })

      expect(await screen.findByText('This image is already in the project.')).toBeInTheDocument()
      expect(screen.getByText('Not uploaded')).toBeInTheDocument()

      await userEvent.click(screen.getByRole('button', { name: 'Dismiss dup.jpg' }))
      expect(screen.queryByText('Not uploaded')).not.toBeInTheDocument()
    })

    it('retries a failed upload', async () => {
      await openEditor(project())

      dropFiles([jpegFile('flaky.jpg')])
      await waitFor(() => expect(FakeXhr.instances).toHaveLength(1))
      FakeXhr.instances[0]?.fail()

      expect(await screen.findByText(/upload was interrupted/i)).toBeInTheDocument()
      await userEvent.click(screen.getByRole('button', { name: 'Retry flaky.jpg' }))

      await waitFor(() => expect(FakeXhr.instances).toHaveLength(2))
      FakeXhr.instances[1]?.respond(201, { data: image(1, { name: 'flaky.jpg' }) })
      expect(await screen.findByAltText('Preview of flaky.jpg')).toBeInTheDocument()
    })

    it('explains a request that was too large for the server', async () => {
      await openEditor(project())

      dropFiles([jpegFile('big.jpg')])
      await waitFor(() => expect(FakeXhr.instances).toHaveLength(1))
      FakeXhr.instances[0]?.respond(413, null)

      expect(await screen.findByText(/larger than the server accepts/)).toBeInTheDocument()
    })

    it('cancels an upload in progress', async () => {
      await openEditor(project())

      dropFiles([jpegFile('slow.jpg')])
      await waitFor(() => expect(FakeXhr.instances).toHaveLength(1))
      await userEvent.click(
        await screen.findByRole('button', { name: 'Cancel upload of slow.jpg' }),
      )

      expect(FakeXhr.instances[0]?.aborted).toBe(true)
      expect(screen.queryByRole('progressbar')).not.toBeInTheDocument()
      expect(screen.queryByText('Not uploaded')).not.toBeInTheDocument()
    })

    it('only takes as many files as the project has room for', async () => {
      await openEditor(project([image(1), image(2), image(3)]))

      dropFiles([jpegFile('a.jpg'), jpegFile('b.jpg'), jpegFile('c.jpg')])

      expect(await screen.findByText(/Only 1 more image fits/)).toBeInTheDocument()
      await waitFor(() => expect(FakeXhr.instances).toHaveLength(1))
      expect((FakeXhr.instances[0]?.body?.get('image') as File).name).toBe('a.jpg')
    })

    it('disables the upload area when the project is full', async () => {
      await openEditor(project([image(1), image(2), image(3), image(4)]))

      expect(screen.getByRole('button', { name: 'Image limit reached' })).toBeDisabled()
      dropFiles([jpegFile('a.jpg')])
      await Promise.resolve()
      expect(FakeXhr.instances).toHaveLength(0)
    })

    it('keeps the page from navigating away when a file is dropped outside the upload area', async () => {
      await openEditor(project())

      const event = new Event('drop', { cancelable: true, bubbles: true })
      Object.defineProperty(event, 'dataTransfer', { value: { types: ['Files'], files: [] } })
      document.body.dispatchEvent(event)

      expect(event.defaultPrevented).toBe(true)
    })
  })

  describe('removing images', () => {
    it('asks first, then deletes the image', async () => {
      const calls = await openEditor(
        project([image(1), image(2)]),
        {},
        {
          [`DELETE ${base}/images/${image(1).id}`]: () => new Response(null, { status: 204 }),
        },
      )

      await userEvent.click(screen.getByRole('button', { name: 'Remove photo-1.jpg' }))
      const confirm = screen.getByRole('group', { name: 'Confirm removing photo-1.jpg' })
      expect(calls.some((call) => call.startsWith('DELETE'))).toBe(false)

      await userEvent.click(within(confirm).getByRole('button', { name: 'Remove' }))

      expect(await screen.findByText('Image removed')).toBeInTheDocument()
      await waitFor(() => expect(tileNames()).toEqual(['photo-2.jpg']))
      expect(screen.getByText('1 / 4')).toBeInTheDocument()
      // The remaining image is now the cover.
      expect(screen.getByText('Cover')).toBeInTheDocument()
    })

    it('keeps the image when the person changes their mind', async () => {
      const calls = await openEditor(project([image(1)]))

      await userEvent.click(screen.getByRole('button', { name: 'Remove photo-1.jpg' }))
      await userEvent.click(screen.getByRole('button', { name: 'Keep' }))

      expect(screen.queryByRole('group', { name: /Confirm removing/ })).not.toBeInTheDocument()
      expect(calls.some((call) => call.startsWith('DELETE'))).toBe(false)
      expect(tileNames()).toEqual(['photo-1.jpg'])
    })
  })

  describe('reordering', () => {
    const three = [image(1), image(2), image(3)]
    const order = `PUT ${base}/images/order`

    it('moves an image with the arrow buttons and saves the new order', async () => {
      let body: unknown
      await openEditor(
        project(three),
        {},
        {
          [order]: (init) => {
            body = JSON.parse(String(init?.body))
            return jsonResponse({
              data: [
                image(2, { position: 1 }),
                image(1, { position: 2 }),
                image(3, { position: 3 }),
              ],
            })
          },
        },
      )

      expect(screen.getByRole('button', { name: 'Move photo-1.jpg earlier' })).toBeDisabled()
      expect(screen.getByRole('button', { name: 'Move photo-3.jpg later' })).toBeDisabled()

      await userEvent.click(screen.getByRole('button', { name: 'Move photo-2.jpg earlier' }))

      await waitFor(() =>
        expect(tileNames()).toEqual(['photo-2.jpg', 'photo-1.jpg', 'photo-3.jpg']),
      )
      expect(body).toEqual({ order: [image(2).id, image(1).id, image(3).id] })
    })

    it('moves an image by dragging it onto another', async () => {
      let body: unknown
      await openEditor(
        project(three),
        {},
        {
          [order]: (init) => {
            body = JSON.parse(String(init?.body))
            return jsonResponse({
              data: [
                image(2, { position: 1 }),
                image(3, { position: 2 }),
                image(1, { position: 3 }),
              ],
            })
          },
        },
      )

      const dataTransfer = { setData: vi.fn(), effectAllowed: '', dropEffect: '' }
      const first = screen.getByRole('listitem', { name: /photo-1.jpg, image 1 of 3/ })
      const last = screen.getByRole('listitem', { name: /photo-3.jpg, image 3 of 3/ })
      expect(first).toHaveAttribute('draggable', 'true')

      fireEvent.dragStart(first, { dataTransfer })
      fireEvent.dragOver(last, { dataTransfer })
      fireEvent.drop(last, { dataTransfer })
      fireEvent.dragEnd(first, { dataTransfer })

      await waitFor(() =>
        expect(tileNames()).toEqual(['photo-2.jpg', 'photo-3.jpg', 'photo-1.jpg']),
      )
      expect(body).toEqual({ order: [image(2).id, image(3).id, image(1).id] })
      expect(screen.getByText('Cover')).toBeInTheDocument()
    })

    it('puts the order back and says why when the server refuses', async () => {
      await openEditor(
        project(three),
        {},
        {
          [order]: () =>
            jsonResponse(
              {
                status: 422,
                code: 'validation_failed',
                detail: 'The request is invalid.',
                errors: {
                  order: ['The order must list every image of this project exactly once.'],
                },
              },
              422,
            ),
        },
      )

      await userEvent.click(screen.getByRole('button', { name: 'Move photo-1.jpg later' }))

      expect(await screen.findByText('Could not change the order')).toBeInTheDocument()
      await waitFor(() =>
        expect(tileNames()).toEqual(['photo-1.jpg', 'photo-2.jpg', 'photo-3.jpg']),
      )
    })

    it('switches reordering off while an upload is running', async () => {
      await openEditor(project(three))

      dropFiles([jpegFile('new.jpg')])
      await screen.findByRole('progressbar', { name: 'Uploading new.jpg' })

      expect(screen.getByRole('button', { name: 'Move photo-2.jpg earlier' })).toBeDisabled()
      expect(screen.getByRole('listitem', { name: /photo-1.jpg, image 1 of 3/ })).toHaveAttribute(
        'draggable',
        'false',
      )
    })
  })

  describe('expired image links', () => {
    it('fetches fresh links when a preview fails to load', async () => {
      let loads = 0
      mockApi({
        ...signedInHandlers(),
        [`GET ${base}`]: () => {
          loads += 1
          // Fresh signed links differ from the expired ones.
          return jsonResponse(
            envelope(
              project([
                image(1, { thumbnail_url: `/api/v1/files/images/1/thumb?signature=${loads}` }),
              ]),
            ),
          )
        },
      })
      renderApp(`/create/${PROJECT_ID}`)

      const preview = await screen.findByAltText('Preview of photo-1.jpg')
      expect(loads).toBe(1)
      fireEvent.error(preview)

      await waitFor(() => expect(loads).toBe(2))
      expect(await screen.findByAltText('Preview of photo-1.jpg')).toHaveAttribute(
        'src',
        '/api/v1/files/images/1/thumb?signature=2',
      )
    })
  })

  describe('deleting the project', () => {
    it('asks first, then deletes it and returns to the list', async () => {
      const calls = await openEditor(
        project([image(1)]),
        {},
        {
          [`DELETE ${base}`]: () => new Response(null, { status: 204 }),
        },
      )

      await userEvent.click(screen.getByRole('button', { name: 'Delete project' }))
      const confirm = screen.getByRole('group', { name: 'Confirm deleting this project' })
      expect(calls.some((call) => call === `DELETE ${base}`)).toBe(false)

      await userEvent.click(within(confirm).getByRole('button', { name: 'Delete project' }))

      expect(await screen.findByText('Start your first video')).toBeInTheDocument()
      expect(calls).toContain(`DELETE ${base}`)
    })
  })
})
