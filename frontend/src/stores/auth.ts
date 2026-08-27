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

interface MessageResponse {
  message?: string
}

interface UserResponse {
  user: AuthUser
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

/** Erreur portant le message renvoyé par l'API (401 et 429 de /auth/login). */
export class AuthMessageError extends Error {
  constructor(message: string) {
    super(message)
    this.name = 'AuthMessageError'
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

  async function login(email: string, password: string): Promise<void> {
    const response = await fetch('/api/auth/login', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json',
      },
      body: JSON.stringify({ email, password }),
    })

    if (response.status === 200) {
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

    // 401 : identifiants refusés, sans distinction email / mot de passe.
    // 429 : plafond de requêtes atteint. Dans les deux cas le message vient du serveur.
    if (response.status === 401 || response.status === 429) {
      const data = (await response.json()) as MessageResponse
      throw new AuthMessageError(data.message ?? 'Connexion impossible.')
    }

    throw new Error(`Réponse inattendue du serveur (statut ${response.status}).`)
  }

  function clearSession(): void {
    token.value = null
    user.value = null
    localStorage.removeItem(TOKEN_STORAGE_KEY)
  }

  /**
   * Purge systématiquement la session locale, quel que soit le résultat de la
   * révocation serveur : un 401 signifie que le jeton est déjà mort côté API,
   * et une panne réseau ne doit pas laisser l'utilisateur « connecté » avec un
   * jeton inutilisable.
   */
  async function logout(): Promise<void> {
    if (token.value) {
      try {
        await fetch('/api/auth/logout', {
          method: 'POST',
          headers: {
            Accept: 'application/json',
            Authorization: `Bearer ${token.value}`,
          },
        })
      } catch {
        // Panne réseau : la purge locale suit quand même.
      }
    }

    clearSession()
  }

  /**
   * Restaure la session au chargement de l'application : sans jeton persisté,
   * aucun appel. Seul un 401 purge la session ; un 429, un 5xx ou une panne
   * réseau ne prouvent pas que le jeton est invalide et laissent l'état intact.
   */
  async function restoreSession(): Promise<void> {
    if (!token.value) {
      return
    }

    let response: Response
    try {
      response = await fetch('/api/auth/me', {
        headers: {
          Accept: 'application/json',
          Authorization: `Bearer ${token.value}`,
        },
      })
    } catch {
      return
    }

    if (response.status === 200) {
      const data = (await response.json()) as UserResponse
      user.value = data.user
      return
    }

    if (response.status === 401) {
      clearSession()
    }
  }

  return { token, user, register, login, logout, clearSession, restoreSession }
})
