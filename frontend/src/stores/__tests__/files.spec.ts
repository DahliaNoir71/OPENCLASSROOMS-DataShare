import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'

import { TOKEN_STORAGE_KEY, useAuthStore } from '../auth'
import {
  ListMessageError,
  ListUnauthenticatedError,
  ListValidationError,
  RemoveMessageError,
  RemoveNotFoundError,
  RemoveUnauthenticatedError,
  UploadMessageError,
  UploadUnauthenticatedError,
  UploadValidationError,
  useFilesStore,
} from '../files'
import { NETWORK_ERROR_MESSAGE } from '@/utils/network'

function jsonResponse(status: number, body: unknown): Response {
  return { status, json: () => Promise.resolve(body) } as unknown as Response
}

const fetchMock = vi.fn<typeof fetch>()

const uploadedFile = {
  id: 1,
  original_name: 'rapport.pdf',
  size: 1024,
  mime_type: 'application/pdf',
  protected: false,
  expires_at: '2026-08-28T10:00:00.000000Z',
  expired: false,
  link: 'https://datashare.test/l/token-abc',
  created_at: '2026-08-21T10:00:00.000000Z',
}

function pdf(): File {
  return new File(['contenu'], 'rapport.pdf', { type: 'application/pdf' })
}

function lastRequestInit(): RequestInit {
  return fetchMock.mock.calls[0]![1] as RequestInit
}

/** Fausse XHR pilotable à la main : `upload()` en a besoin, `fetch` reste mocké pour list/remove. */
class FakeXMLHttpRequest {
  static instances: FakeXMLHttpRequest[] = []

  method = ''
  url = ''
  status = 0
  responseText = ''
  headers: Record<string, string> = {}
  body: unknown
  upload: { onprogress: ((event: ProgressEvent) => void) | null } = { onprogress: null }
  onload: (() => void) | null = null
  onerror: (() => void) | null = null

  open(method: string, url: string): void {
    this.method = method
    this.url = url
  }

  setRequestHeader(key: string, value: string): void {
    this.headers[key] = value
  }

  send(body: unknown): void {
    this.body = body
    FakeXMLHttpRequest.instances.push(this)
  }

  respond(status: number, responseText: string): void {
    this.status = status
    this.responseText = responseText
    this.onload?.()
  }

  progress(loaded: number, total: number, lengthComputable = true): void {
    this.upload.onprogress?.({ loaded, total, lengthComputable } as ProgressEvent)
  }

  fail(): void {
    this.onerror?.()
  }
}

function lastXhr(): FakeXMLHttpRequest {
  return FakeXMLHttpRequest.instances[FakeXMLHttpRequest.instances.length - 1]!
}

beforeEach(() => {
  localStorage.clear()
  localStorage.setItem(TOKEN_STORAGE_KEY, 'jwt-test')
  fetchMock.mockReset()
  vi.stubGlobal('fetch', fetchMock)
  FakeXMLHttpRequest.instances = []
  vi.stubGlobal('XMLHttpRequest', FakeXMLHttpRequest)
  setActivePinia(createPinia())
})

afterEach(() => {
  vi.unstubAllGlobals()
})

describe('useFilesStore', () => {
  it('poste un FormData authentifié et retourne le fichier créé sur un 201', async () => {
    const store = useFilesStore()
    const file = pdf()

    const resultPromise = store.upload(file, { password: 'motdepasse', expiresInDays: 3 })
    const xhr = lastXhr()

    expect(FakeXMLHttpRequest.instances).toHaveLength(1)
    expect(xhr.method).toBe('POST')
    expect(xhr.url).toBe('/api/files')

    const body = xhr.body as FormData
    expect(body).toBeInstanceOf(FormData)
    expect(body.get('file')).toBe(file)
    expect(body.get('password')).toBe('motdepasse')
    expect(body.get('expires_in_days')).toBe('3')

    xhr.respond(201, JSON.stringify({ data: uploadedFile }))

    expect(await resultPromise).toEqual(uploadedFile)
  })

  it('ne fixe aucun Content-Type : le boundary multipart appartient au navigateur', async () => {
    const resultPromise = useFilesStore().upload(pdf(), { expiresInDays: 7 })
    const xhr = lastXhr()

    expect(xhr.headers).toEqual({
      Accept: 'application/json',
      Authorization: 'Bearer jwt-test',
    })

    xhr.respond(201, JSON.stringify({ data: uploadedFile }))
    await resultPromise
  })

  it("n'envoie pas le champ password quand il est vide", async () => {
    const resultPromise = useFilesStore().upload(pdf(), { password: '', expiresInDays: 7 })
    const xhr = lastXhr()

    expect((xhr.body as FormData).has('password')).toBe(false)

    xhr.respond(201, JSON.stringify({ data: uploadedFile }))
    await resultPromise
  })

  it('propage les erreurs par champ sur un 422', async () => {
    const resultPromise = useFilesStore().upload(pdf(), { expiresInDays: 7 })
    lastXhr().respond(
      422,
      JSON.stringify({
        message: 'The given data was invalid.',
        errors: { file: ["Ce type de fichier n'est pas autorisé."] },
      }),
    )

    await expect(resultPromise).rejects.toMatchObject({
      name: 'UploadValidationError',
      errors: { file: ["Ce type de fichier n'est pas autorisé."] },
    })
  })

  it('purge la session sur un 401', async () => {
    const authStore = useAuthStore()

    const resultPromise = useFilesStore().upload(pdf(), { expiresInDays: 7 })
    lastXhr().respond(401, JSON.stringify({ message: 'Unauthenticated.' }))

    await expect(resultPromise).rejects.toBeInstanceOf(UploadUnauthenticatedError)

    expect(authStore.token).toBeNull()
    expect(localStorage.getItem(TOKEN_STORAGE_KEY)).toBeNull()
  })

  it('remonte le message du serveur sur un 413 et sur un 429', async () => {
    const store = useFilesStore()

    const firstPromise = store.upload(pdf(), { expiresInDays: 7 })
    lastXhr().respond(413, JSON.stringify({ message: 'Le fichier envoyé est trop volumineux.' }))
    await expect(firstPromise).rejects.toThrow('Le fichier envoyé est trop volumineux.')

    const secondPromise = store.upload(pdf(), { expiresInDays: 7 })
    lastXhr().respond(429, JSON.stringify({ message: 'Trop de requêtes.' }))
    await expect(secondPromise).rejects.toThrow('Trop de requêtes.')
  })

  it("se rabat sur un message français quand le corps du 413 n'est pas du JSON", async () => {
    const resultPromise = useFilesStore().upload(pdf(), { expiresInDays: 7 })
    lastXhr().respond(413, 'Not JSON')

    const error = await resultPromise.catch((caught: unknown) => caught)

    expect(error).toBeInstanceOf(UploadMessageError)
    expect((error as Error).message).toBe('Le fichier envoyé est trop volumineux.')
  })

  it('signale un statut inattendu sans le confondre avec une erreur de validation', async () => {
    const resultPromise = useFilesStore().upload(pdf(), { expiresInDays: 7 })
    lastXhr().respond(500, '{}')

    const error = await resultPromise.catch((caught: unknown) => caught)

    expect(error).not.toBeInstanceOf(UploadValidationError)
    expect((error as Error).message).toBe('Réponse inattendue du serveur (statut 500).')
  })

  it('remonte un UploadMessageError avec un message stable sur une panne réseau', async () => {
    const resultPromise = useFilesStore().upload(pdf(), { expiresInDays: 7 })
    lastXhr().fail()

    const error = await resultPromise.catch((caught: unknown) => caught)

    expect(error).toBeInstanceOf(UploadMessageError)
    expect((error as Error).message).toBe(NETWORK_ERROR_MESSAGE)
  })

  it('reporte la progression arrondie au callback pendant l’envoi', async () => {
    const onProgress = vi.fn()

    const resultPromise = useFilesStore().upload(pdf(), { expiresInDays: 7, onProgress })
    const xhr = lastXhr()

    xhr.progress(33, 100)
    xhr.progress(2, 3)
    xhr.respond(201, JSON.stringify({ data: uploadedFile }))
    await resultPromise

    expect(onProgress).toHaveBeenCalledTimes(2)
    expect(onProgress).toHaveBeenNthCalledWith(1, 33)
    expect(onProgress).toHaveBeenNthCalledWith(2, 67)
  })

  it("n'appelle jamais le callback de progression quand la taille totale n'est pas calculable", async () => {
    const onProgress = vi.fn()

    const resultPromise = useFilesStore().upload(pdf(), { expiresInDays: 7, onProgress })
    const xhr = lastXhr()

    xhr.progress(10, 0, false)
    xhr.respond(201, JSON.stringify({ data: uploadedFile }))
    await resultPromise

    expect(onProgress).not.toHaveBeenCalled()
  })

  describe('list', () => {
    const filesPage = {
      data: [uploadedFile],
      links: { first: '/api/files?page=1', last: '/api/files?page=1', prev: null, next: null },
      meta: {
        current_page: 1,
        from: 1,
        last_page: 1,
        path: '/api/files',
        per_page: 25,
        to: 1,
        total: 1,
      },
    }

    it('interroge /api/files sans paramètre quand aucune option n’est fournie', async () => {
      fetchMock.mockResolvedValue(jsonResponse(200, filesPage))

      const result = await useFilesStore().list()

      expect(fetchMock).toHaveBeenCalledTimes(1)
      expect(fetchMock.mock.calls[0]![0]).toBe('/api/files')
      expect(lastRequestInit().headers).toEqual({
        Accept: 'application/json',
        Authorization: 'Bearer jwt-test',
      })
      expect(result).toEqual(filesPage)
    })

    it('reporte status, page et per_page dans la chaîne de requête', async () => {
      fetchMock.mockResolvedValue(jsonResponse(200, filesPage))

      await useFilesStore().list({ status: 'active', page: 2, perPage: 10 })

      expect(fetchMock.mock.calls[0]![0]).toBe('/api/files?status=active&page=2&per_page=10')
    })

    it('purge la session sur un 401', async () => {
      fetchMock.mockResolvedValue(jsonResponse(401, { message: 'Unauthenticated.' }))
      const authStore = useAuthStore()

      await expect(useFilesStore().list()).rejects.toBeInstanceOf(ListUnauthenticatedError)

      expect(authStore.token).toBeNull()
      expect(localStorage.getItem(TOKEN_STORAGE_KEY)).toBeNull()
    })

    it('propage les erreurs par champ sur un 422', async () => {
      fetchMock.mockResolvedValue(
        jsonResponse(422, {
          message: 'The given data was invalid.',
          errors: { status: ['Le filtre demandé est invalide.'] },
        }),
      )

      await expect(useFilesStore().list({ status: 'active' })).rejects.toMatchObject({
        name: 'ListValidationError',
        errors: { status: ['Le filtre demandé est invalide.'] },
      })
    })

    it('remonte le message du serveur sur un 429', async () => {
      fetchMock.mockResolvedValue(jsonResponse(429, { message: 'Trop de requêtes.' }))

      await expect(useFilesStore().list()).rejects.toBeInstanceOf(ListMessageError)
      await expect(useFilesStore().list()).rejects.toThrow('Trop de requêtes.')
    })

    it('signale un statut inattendu sans le confondre avec une erreur de validation', async () => {
      fetchMock.mockResolvedValue(jsonResponse(500, {}))

      const error = await useFilesStore()
        .list()
        .catch((caught: unknown) => caught)

      expect(error).not.toBeInstanceOf(ListValidationError)
      expect((error as Error).message).toBe('Réponse inattendue du serveur (statut 500).')
    })

    it('remonte un ListMessageError avec un message stable sur une panne réseau', async () => {
      fetchMock.mockRejectedValue(new TypeError('Failed to fetch'))

      const error = await useFilesStore()
        .list()
        .catch((caught: unknown) => caught)

      expect(error).toBeInstanceOf(ListMessageError)
      expect((error as Error).message).toBe(NETWORK_ERROR_MESSAGE)
    })
  })

  describe('remove', () => {
    it('envoie un DELETE authentifié sur /api/files/{id}', async () => {
      fetchMock.mockResolvedValue(jsonResponse(204, {}))

      await useFilesStore().remove(1)

      expect(fetchMock).toHaveBeenCalledTimes(1)
      expect(fetchMock.mock.calls[0]![0]).toBe('/api/files/1')
      expect(lastRequestInit().method).toBe('DELETE')
      expect(lastRequestInit().headers).toEqual({
        Accept: 'application/json',
        Authorization: 'Bearer jwt-test',
      })
    })

    it("ne lève rien sur un 204 sans corps", async () => {
      fetchMock.mockResolvedValue(jsonResponse(204, {}))

      await expect(useFilesStore().remove(1)).resolves.toBeUndefined()
    })

    it('purge la session sur un 401', async () => {
      fetchMock.mockResolvedValue(jsonResponse(401, { message: 'Unauthenticated.' }))
      const authStore = useAuthStore()

      await expect(useFilesStore().remove(1)).rejects.toBeInstanceOf(RemoveUnauthenticatedError)

      expect(authStore.token).toBeNull()
      expect(localStorage.getItem(TOKEN_STORAGE_KEY)).toBeNull()
    })

    it('remonte un RemoveNotFoundError sur un 404 (liste périmée)', async () => {
      fetchMock.mockResolvedValue(jsonResponse(404, { message: 'Ce fichier est introuvable.' }))

      const error = await useFilesStore()
        .remove(1)
        .catch((caught: unknown) => caught)

      expect(error).toBeInstanceOf(RemoveNotFoundError)
      expect((error as Error).message).toBe('Ce fichier est introuvable.')
    })

    it('remonte le message du serveur sur un 429', async () => {
      fetchMock.mockResolvedValue(jsonResponse(429, { message: 'Trop de requêtes.' }))

      await expect(useFilesStore().remove(1)).rejects.toBeInstanceOf(RemoveMessageError)
      await expect(useFilesStore().remove(1)).rejects.toThrow('Trop de requêtes.')
    })

    it('signale un statut inattendu sans le confondre avec un 404', async () => {
      fetchMock.mockResolvedValue(jsonResponse(500, {}))

      const error = await useFilesStore()
        .remove(1)
        .catch((caught: unknown) => caught)

      expect(error).not.toBeInstanceOf(RemoveNotFoundError)
      expect((error as Error).message).toBe('Réponse inattendue du serveur (statut 500).')
    })

    it('remonte un RemoveMessageError avec un message stable sur une panne réseau', async () => {
      fetchMock.mockRejectedValue(new TypeError('Failed to fetch'))

      const error = await useFilesStore()
        .remove(1)
        .catch((caught: unknown) => caught)

      expect(error).toBeInstanceOf(RemoveMessageError)
      expect((error as Error).message).toBe(NETWORK_ERROR_MESSAGE)
    })
  })
})
