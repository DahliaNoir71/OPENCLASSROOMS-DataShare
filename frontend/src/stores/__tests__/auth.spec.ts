import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'

import { RegisterValidationError, TOKEN_STORAGE_KEY, useAuthStore } from '../auth'

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
})
