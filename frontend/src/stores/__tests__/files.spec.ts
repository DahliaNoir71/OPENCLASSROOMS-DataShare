import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'

import { TOKEN_STORAGE_KEY, useAuthStore } from '../auth'
import {
  ListMessageError,
  ListUnauthenticatedError,
  ListValidationError,
  UploadMessageError,
  UploadUnauthenticatedError,
  UploadValidationError,
  useFilesStore,
} from '../files'

function jsonResponse(status: number, body: unknown): Response {
  return { status, json: () => Promise.resolve(body) } as unknown as Response
}

/** Un 413 produit hors de l'application n'a pas de corps JSON exploitable. */
function nonJsonResponse(status: number): Response {
  return { status, json: () => Promise.reject(new SyntaxError('Unexpected token')) } as Response
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

beforeEach(() => {
  localStorage.clear()
  localStorage.setItem(TOKEN_STORAGE_KEY, 'jwt-test')
  fetchMock.mockReset()
  vi.stubGlobal('fetch', fetchMock)
  setActivePinia(createPinia())
})

afterEach(() => {
  vi.unstubAllGlobals()
})

describe('useFilesStore', () => {
  it('poste un FormData authentifié et retourne le fichier créé sur un 201', async () => {
    fetchMock.mockResolvedValue(jsonResponse(201, { data: uploadedFile }))
    const store = useFilesStore()
    const file = pdf()

    const result = await store.upload(file, { password: 'motdepasse', expiresInDays: 3 })

    expect(fetchMock).toHaveBeenCalledTimes(1)
    expect(fetchMock.mock.calls[0]![0]).toBe('/api/files')

    const init = lastRequestInit()
    expect(init.method).toBe('POST')

    const body = init.body as FormData
    expect(body).toBeInstanceOf(FormData)
    expect(body.get('file')).toBe(file)
    expect(body.get('password')).toBe('motdepasse')
    expect(body.get('expires_in_days')).toBe('3')

    expect(result).toEqual(uploadedFile)
  })

  it('ne fixe aucun Content-Type : le boundary multipart appartient au navigateur', async () => {
    fetchMock.mockResolvedValue(jsonResponse(201, { data: uploadedFile }))

    await useFilesStore().upload(pdf(), { expiresInDays: 7 })

    expect(lastRequestInit().headers).toEqual({
      Accept: 'application/json',
      Authorization: 'Bearer jwt-test',
    })
  })

  it("n'envoie pas le champ password quand il est vide", async () => {
    fetchMock.mockResolvedValue(jsonResponse(201, { data: uploadedFile }))

    await useFilesStore().upload(pdf(), { password: '', expiresInDays: 7 })

    expect((lastRequestInit().body as FormData).has('password')).toBe(false)
  })

  it('propage les erreurs par champ sur un 422', async () => {
    fetchMock.mockResolvedValue(
      jsonResponse(422, {
        message: 'The given data was invalid.',
        errors: { file: ["Ce type de fichier n'est pas autorisé."] },
      }),
    )

    await expect(useFilesStore().upload(pdf(), { expiresInDays: 7 })).rejects.toMatchObject({
      name: 'UploadValidationError',
      errors: { file: ["Ce type de fichier n'est pas autorisé."] },
    })
  })

  it('purge la session sur un 401', async () => {
    fetchMock.mockResolvedValue(jsonResponse(401, { message: 'Unauthenticated.' }))
    const authStore = useAuthStore()

    await expect(useFilesStore().upload(pdf(), { expiresInDays: 7 })).rejects.toBeInstanceOf(
      UploadUnauthenticatedError,
    )

    expect(authStore.token).toBeNull()
    expect(localStorage.getItem(TOKEN_STORAGE_KEY)).toBeNull()
  })

  it('remonte le message du serveur sur un 413 et sur un 429', async () => {
    fetchMock.mockResolvedValueOnce(
      jsonResponse(413, { message: 'Le fichier envoyé est trop volumineux.' }),
    )
    await expect(useFilesStore().upload(pdf(), { expiresInDays: 7 })).rejects.toThrow(
      'Le fichier envoyé est trop volumineux.',
    )

    fetchMock.mockResolvedValueOnce(jsonResponse(429, { message: 'Trop de requêtes.' }))
    await expect(useFilesStore().upload(pdf(), { expiresInDays: 7 })).rejects.toThrow(
      'Trop de requêtes.',
    )
  })

  it("se rabat sur un message français quand le corps du 413 n'est pas du JSON", async () => {
    fetchMock.mockResolvedValue(nonJsonResponse(413))

    const error = await useFilesStore()
      .upload(pdf(), { expiresInDays: 7 })
      .catch((caught: unknown) => caught)

    expect(error).toBeInstanceOf(UploadMessageError)
    expect((error as Error).message).toBe('Le fichier envoyé est trop volumineux.')
  })

  it('signale un statut inattendu sans le confondre avec une erreur de validation', async () => {
    fetchMock.mockResolvedValue(jsonResponse(500, {}))

    const error = await useFilesStore()
      .upload(pdf(), { expiresInDays: 7 })
      .catch((caught: unknown) => caught)

    expect(error).not.toBeInstanceOf(UploadValidationError)
    expect((error as Error).message).toBe('Réponse inattendue du serveur (statut 500).')
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
  })
})
