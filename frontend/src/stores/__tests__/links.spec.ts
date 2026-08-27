import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'

import {
  LinkGoneError,
  LinkMessageError,
  LinkNotFoundError,
  LinkPasswordError,
  LinkValidationError,
  useLinksStore,
} from '../links'

function jsonResponse(status: number, body: unknown): Response {
  return { status, json: () => Promise.resolve(body) } as unknown as Response
}

/** Un 413 produit hors de l'application n'a pas de corps JSON exploitable. */
function nonJsonResponse(status: number): Response {
  return { status, json: () => Promise.reject(new SyntaxError('Unexpected token')) } as Response
}

function blobResponse(status: number, blob: Blob): Response {
  return { status, blob: () => Promise.resolve(blob) } as unknown as Response
}

const fetchMock = vi.fn<typeof fetch>()

const metadata = {
  original_name: 'rapport.pdf',
  size: 2048,
  mime_type: 'application/pdf',
  protected: false,
  expires_at: '2026-09-04T10:00:00.000000Z',
}

function lastRequestInit(): RequestInit {
  return fetchMock.mock.calls[0]![1] as RequestInit
}

beforeEach(() => {
  fetchMock.mockReset()
  vi.stubGlobal('fetch', fetchMock)
  setActivePinia(createPinia())
})

afterEach(() => {
  vi.unstubAllGlobals()
})

describe('useLinksStore', () => {
  describe('metadata', () => {
    it('interroge GET /api/links/{token} sans Authorization', async () => {
      fetchMock.mockResolvedValue(jsonResponse(200, metadata))

      await useLinksStore().metadata('token-abc')

      expect(fetchMock.mock.calls[0]![0]).toBe('/api/links/token-abc')
      expect(lastRequestInit().headers).toEqual({ Accept: 'application/json' })
    })

    it('encode le token dans l’URL', async () => {
      fetchMock.mockResolvedValue(jsonResponse(200, metadata))

      await useLinksStore().metadata('a/b?c=d')

      expect(fetchMock.mock.calls[0]![0]).toBe('/api/links/a%2Fb%3Fc%3Dd')
    })

    it('retourne les 5 champs à plat sur un 200', async () => {
      fetchMock.mockResolvedValue(jsonResponse(200, metadata))

      const result = await useLinksStore().metadata('token-abc')

      expect(result).toEqual(metadata)
    })

    it('lève LinkNotFoundError avec le message du serveur sur un 404', async () => {
      fetchMock.mockResolvedValue(
        jsonResponse(404, { message: 'Ce lien de téléchargement est invalide.' }),
      )

      await expect(useLinksStore().metadata('token-abc')).rejects.toMatchObject({
        name: 'LinkNotFoundError',
        message: 'Ce lien de téléchargement est invalide.',
      })
      await expect(useLinksStore().metadata('token-abc')).rejects.toBeInstanceOf(
        LinkNotFoundError,
      )
    })

    it('lève LinkGoneError avec le message du serveur sur un 410', async () => {
      fetchMock.mockResolvedValue(
        jsonResponse(410, {
          message: "Ce lien a expiré : le fichier n'est plus disponible.",
        }),
      )

      await expect(useLinksStore().metadata('token-abc')).rejects.toMatchObject({
        name: 'LinkGoneError',
        message: "Ce lien a expiré : le fichier n'est plus disponible.",
      })
      await expect(useLinksStore().metadata('token-abc')).rejects.toBeInstanceOf(LinkGoneError)
    })

    it('lève LinkMessageError avec le message du serveur sur un 429', async () => {
      fetchMock.mockResolvedValue(jsonResponse(429, { message: 'Trop de requêtes.' }))

      await expect(useLinksStore().metadata('token-abc')).rejects.toMatchObject({
        name: 'LinkMessageError',
        message: 'Trop de requêtes.',
      })
    })

    it("se rabat sur un message français quand le corps n'est pas du JSON", async () => {
      fetchMock.mockResolvedValue(nonJsonResponse(404))

      await expect(useLinksStore().metadata('token-abc')).rejects.toMatchObject({
        name: 'LinkNotFoundError',
        message: 'Ce lien de téléchargement est invalide.',
      })
    })

    it('signale un statut inattendu sans le confondre avec une erreur connue', async () => {
      fetchMock.mockResolvedValue(jsonResponse(500, {}))

      await expect(useLinksStore().metadata('token-abc')).rejects.toThrow(
        'Réponse inattendue du serveur (statut 500).',
      )
    })
  })

  describe('download', () => {
    it('poste un corps JSON vide sans mot de passe', async () => {
      fetchMock.mockResolvedValue(blobResponse(200, new Blob(['contenu'])))

      await useLinksStore().download('token-abc')

      expect(fetchMock.mock.calls[0]![0]).toBe('/api/links/token-abc/download')
      const init = lastRequestInit()
      expect(init.method).toBe('POST')
      expect(init.body).toBe('{}')
    })

    it('poste { password } quand il est fourni', async () => {
      fetchMock.mockResolvedValue(blobResponse(200, new Blob(['contenu'])))

      await useLinksStore().download('token-abc', 'motdepasse')

      expect(lastRequestInit().body).toBe(JSON.stringify({ password: 'motdepasse' }))
    })

    it("n'envoie aucun Authorization", async () => {
      fetchMock.mockResolvedValue(blobResponse(200, new Blob(['contenu'])))

      await useLinksStore().download('token-abc')

      expect(lastRequestInit().headers).toEqual({
        'Content-Type': 'application/json',
        Accept: 'application/json',
      })
    })

    it('retourne le Blob du corps sur un 200', async () => {
      const blob = new Blob(['contenu'], { type: 'application/pdf' })
      fetchMock.mockResolvedValue(blobResponse(200, blob))

      const result = await useLinksStore().download('token-abc')

      expect(result).toBe(blob)
    })

    it('lève LinkPasswordError sur un 401', async () => {
      fetchMock.mockResolvedValue(jsonResponse(401, { message: 'Mot de passe incorrect.' }))

      await expect(useLinksStore().download('token-abc', 'mauvais')).rejects.toMatchObject({
        name: 'LinkPasswordError',
        message: 'Mot de passe incorrect.',
      })
      await expect(
        useLinksStore().download('token-abc', 'mauvais'),
      ).rejects.toBeInstanceOf(LinkPasswordError)
    })

    it('lève LinkGoneError sur un 410 (échu entre affichage et clic)', async () => {
      fetchMock.mockResolvedValue(
        jsonResponse(410, {
          message: "Ce lien a expiré : le fichier n'est plus disponible.",
        }),
      )

      await expect(useLinksStore().download('token-abc')).rejects.toBeInstanceOf(LinkGoneError)
    })

    it('lève LinkNotFoundError sur un 404 (supprimé entre-temps, US06)', async () => {
      fetchMock.mockResolvedValue(
        jsonResponse(404, { message: 'Ce lien de téléchargement est invalide.' }),
      )

      await expect(useLinksStore().download('token-abc')).rejects.toBeInstanceOf(
        LinkNotFoundError,
      )
    })

    it('lève LinkMessageError sur un 429', async () => {
      fetchMock.mockResolvedValue(jsonResponse(429, { message: 'Trop de requêtes.' }))

      await expect(useLinksStore().download('token-abc')).rejects.toMatchObject({
        name: 'LinkMessageError',
        message: 'Trop de requêtes.',
      })
    })

    it('propage errors.password sur un 422', async () => {
      fetchMock.mockResolvedValue(
        jsonResponse(422, {
          message: 'The given data was invalid.',
          errors: { password: ['Le mot de passe ne doit pas dépasser 72 caractères.'] },
        }),
      )

      await expect(useLinksStore().download('token-abc', 'x'.repeat(73))).rejects.toMatchObject({
        name: 'LinkValidationError',
        errors: { password: ['Le mot de passe ne doit pas dépasser 72 caractères.'] },
      })
      await expect(
        useLinksStore().download('token-abc', 'x'.repeat(73)),
      ).rejects.toBeInstanceOf(LinkValidationError)
    })

    it('signale un statut inattendu sans le confondre avec une erreur connue', async () => {
      fetchMock.mockResolvedValue(jsonResponse(500, {}))

      await expect(useLinksStore().download('token-abc')).rejects.toThrow(
        'Réponse inattendue du serveur (statut 500).',
      )
    })
  })
})
