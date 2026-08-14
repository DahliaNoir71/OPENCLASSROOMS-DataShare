import { ref } from 'vue'
import { defineStore } from 'pinia'

export type ValidationErrors = Record<string, string[]>

export interface AuthUser {
  id: number
  email: string
}

interface AuthResponse {
  token: string
  user: AuthUser
}

interface ValidationErrorResponse {
  message?: string
  errors?: ValidationErrors
}

export const TOKEN_STORAGE_KEY = 'datashare_token'

export class RegisterValidationError extends Error {
  readonly errors: ValidationErrors

  constructor(message: string, errors: ValidationErrors) {
    super(message)
    this.name = 'RegisterValidationError'
    this.errors = errors
  }
}

export const useAuthStore = defineStore('auth', () => {
  const token = ref<string | null>(localStorage.getItem(TOKEN_STORAGE_KEY))
  const user = ref<AuthUser | null>(null)

  async function register(
    email: string,
    password: string,
    passwordConfirmation: string,
  ): Promise<void> {
    const response = await fetch('/api/auth/register', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json',
      },
      body: JSON.stringify({
        email,
        password,
        password_confirmation: passwordConfirmation,
      }),
    })

    if (response.status === 201) {
      const data = (await response.json()) as AuthResponse
      token.value = data.token
      user.value = data.user
      localStorage.setItem(TOKEN_STORAGE_KEY, data.token)
      return
    }

    if (response.status === 422) {
      const data = (await response.json()) as ValidationErrorResponse
      throw new RegisterValidationError(data.message ?? 'Erreur de validation.', data.errors ?? {})
    }

    throw new Error(`Réponse inattendue du serveur (statut ${response.status}).`)
  }

  return { token, user, register }
})
