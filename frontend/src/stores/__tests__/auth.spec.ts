import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'

import {
  AuthMessageError,
  RegisterValidationError,
  TOKEN_STORAGE_KEY,
  useAuthStore,
} from '../auth'

function jsonResponse(status: number, body: unknown): Response {
  return { status, json: () => Promise.resolve(body) } as unknown as Response
}

const fetchMock = vi.fn<typeof fetch>()

beforeEach(() => {
  localStorage.clear()
  fetchMock.mockReset()
  vi.stubGlobal('fetch', fetchMock)
  setActivePinia(createPinia())
})

afterEach(() => {
  vi.unstubAllGlobals()
})

describe('useAuthStore', () => {
  it('alimente le state et localStorage sur une réponse 201', async () => {
    fetchMock.mockResolvedValue(
      jsonResponse(201, { token: 'jwt-test', user: { id: 1, email: 'user@example.com' } }),
    )
    const store = useAuthStore()

    await store.register('user@example.com', 'motdepasse123', 'motdepasse123')

    expect(fetchMock).toHaveBeenCalledWith('/api/auth/register', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json',
      },
      body: JSON.stringify({
        email: 'user@example.com',
        password: 'motdepasse123',
        password_confirmation: 'motdepasse123',
      }),
    })
    expect(store.token).toBe('jwt-test')
    expect(store.user).toEqual({ id: 1, email: 'user@example.com' })
    expect(localStorage.getItem(TOKEN_STORAGE_KEY)).toBe('jwt-test')
  })

  it('propage des erreurs typées et laisse le state inchangé sur une réponse 422', async () => {
    fetchMock.mockResolvedValue(
      jsonResponse(422, {
        message: 'The given data was invalid.',
        errors: { email: ['The email has already been taken.'] },
      }),
    )
    const store = useAuthStore()

    const promise = store.register('user@example.com', 'motdepasse123', 'motdepasse123')

    await expect(promise).rejects.toBeInstanceOf(RegisterValidationError)
    await expect(promise).rejects.toMatchObject({
      errors: { email: ['The email has already been taken.'] },
    })
    expect(store.token).toBeNull()
    expect(store.user).toBeNull()
    expect(localStorage.getItem(TOKEN_STORAGE_KEY)).toBeNull()
  })

  describe('login', () => {
    it('stocke le token et le user, et les persiste, sur une réponse 200', async () => {
      fetchMock.mockResolvedValue(
        jsonResponse(200, { token: 'jwt-login', user: { id: 7, email: 'user@example.com' } }),
      )
      const store = useAuthStore()

      await store.login('user@example.com', 'motdepasse123')

      expect(fetchMock).toHaveBeenCalledWith('/api/auth/login', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          Accept: 'application/json',
        },
        body: JSON.stringify({ email: 'user@example.com', password: 'motdepasse123' }),
      })
      expect(store.token).toBe('jwt-login')
      expect(store.user).toEqual({ id: 7, email: 'user@example.com' })
      expect(localStorage.getItem(TOKEN_STORAGE_KEY)).toBe('jwt-login')
    })

    it("expose le message du serveur et laisse l'état inchangé sur une réponse 401", async () => {
      fetchMock.mockResolvedValue(jsonResponse(401, { message: 'Identifiants invalides.' }))
      const store = useAuthStore()

      const promise = store.login('user@example.com', 'mauvais')

      await expect(promise).rejects.toBeInstanceOf(AuthMessageError)
      await expect(promise).rejects.toThrow('Identifiants invalides.')
      expect(store.token).toBeNull()
      expect(store.user).toBeNull()
      expect(localStorage.getItem(TOKEN_STORAGE_KEY)).toBeNull()
    })

    it('expose le message du serveur sur une réponse 429', async () => {
      fetchMock.mockResolvedValue(jsonResponse(429, { message: 'Trop de tentatives.' }))
      const store = useAuthStore()

      await expect(store.login('user@example.com', 'motdepasse123')).rejects.toThrow(
        'Trop de tentatives.',
      )
    })

    it('propage une RegisterValidationError sur une réponse 422', async () => {
      fetchMock.mockResolvedValue(
        jsonResponse(422, {
          message: 'The given data was invalid.',
          errors: { email: ['The email field is required.'] },
        }),
      )
      const store = useAuthStore()

      await expect(store.login('', 'motdepasse123')).rejects.toBeInstanceOf(
        RegisterValidationError,
      )
    })
  })

  describe('restoreSession', () => {
    it("appelle /auth/me avec le jeton persisté et restaure le user sur un 200", async () => {
      localStorage.setItem(TOKEN_STORAGE_KEY, 'jwt-persiste')
      fetchMock.mockResolvedValue(jsonResponse(200, { user: { id: 7, email: 'user@example.com' } }))
      const store = useAuthStore()

      await store.restoreSession()

      expect(fetchMock).toHaveBeenCalledWith('/api/auth/me', {
        headers: {
          Accept: 'application/json',
          Authorization: 'Bearer jwt-persiste',
        },
      })
      expect(store.token).toBe('jwt-persiste')
      expect(store.user).toEqual({ id: 7, email: 'user@example.com' })
    })

    it('purge le jeton du store et de localStorage sur un 401', async () => {
      localStorage.setItem(TOKEN_STORAGE_KEY, 'jwt-revoque')
      fetchMock.mockResolvedValue(jsonResponse(401, { message: 'Unauthenticated.' }))
      const store = useAuthStore()

      await store.restoreSession()

      expect(store.token).toBeNull()
      expect(store.user).toBeNull()
      expect(localStorage.getItem(TOKEN_STORAGE_KEY)).toBeNull()
    })

    it("conserve le jeton sur un 429, qui ne prouve pas l'invalidité du jeton", async () => {
      localStorage.setItem(TOKEN_STORAGE_KEY, 'jwt-persiste')
      fetchMock.mockResolvedValue(jsonResponse(429, { message: 'Trop de requêtes.' }))
      const store = useAuthStore()

      await store.restoreSession()

      expect(store.token).toBe('jwt-persiste')
      expect(localStorage.getItem(TOKEN_STORAGE_KEY)).toBe('jwt-persiste')
    })

    it('conserve le jeton quand le réseau échoue', async () => {
      localStorage.setItem(TOKEN_STORAGE_KEY, 'jwt-persiste')
      fetchMock.mockRejectedValue(new TypeError('Failed to fetch'))
      const store = useAuthStore()

      await expect(store.restoreSession()).resolves.toBeUndefined()

      expect(store.token).toBe('jwt-persiste')
      expect(localStorage.getItem(TOKEN_STORAGE_KEY)).toBe('jwt-persiste')
    })

    it("n'appelle pas /auth/me en l'absence de jeton persisté", async () => {
      const store = useAuthStore()

      await store.restoreSession()

      expect(fetchMock).not.toHaveBeenCalled()
      expect(store.user).toBeNull()
    })
  })

  describe('logout', () => {
    it('purge la session sur une réponse 200', async () => {
      localStorage.setItem(TOKEN_STORAGE_KEY, 'jwt-persiste')
      fetchMock.mockResolvedValue(jsonResponse(200, {}))
      const store = useAuthStore()

      await store.logout()

      expect(fetchMock).toHaveBeenCalledWith('/api/auth/logout', {
        method: 'POST',
        headers: {
          Accept: 'application/json',
          Authorization: 'Bearer jwt-persiste',
        },
      })
      expect(store.token).toBeNull()
      expect(store.user).toBeNull()
      expect(localStorage.getItem(TOKEN_STORAGE_KEY)).toBeNull()
    })

    it('purge la session sur une réponse 401 (jeton déjà révoqué côté serveur)', async () => {
      localStorage.setItem(TOKEN_STORAGE_KEY, 'jwt-deja-mort')
      fetchMock.mockResolvedValue(jsonResponse(401, { message: 'Unauthenticated.' }))
      const store = useAuthStore()

      await store.logout()

      expect(store.token).toBeNull()
      expect(localStorage.getItem(TOKEN_STORAGE_KEY)).toBeNull()
    })

    it('purge la session quand le réseau échoue', async () => {
      localStorage.setItem(TOKEN_STORAGE_KEY, 'jwt-persiste')
      fetchMock.mockRejectedValue(new TypeError('Failed to fetch'))
      const store = useAuthStore()

      await expect(store.logout()).resolves.toBeUndefined()

      expect(store.token).toBeNull()
      expect(localStorage.getItem(TOKEN_STORAGE_KEY)).toBeNull()
    })
  })
})
