import { defineStore } from 'pinia'

import { useAuthStore, type ValidationErrors } from './auth'

/** Champs de `data` renvoyés par POST /api/files (docs/openapi.yaml, FileResource). */
export interface UploadedFile {
  id: number
  original_name: string
  size: number
  mime_type: string
  protected: boolean
  expires_at: string
  expired: boolean
  link: string
  created_at: string
}

export interface UploadOptions {
  password?: string
  expiresInDays: number
}

interface UploadResponse {
  data: UploadedFile
}

interface ValidationErrorResponse {
  message?: string
  errors?: ValidationErrors
}

interface MessageResponse {
  message?: string
}

/** 422 : erreurs de validation serveur, indexées par champ (`file`, `password`, `expires_in_days`). */
export class UploadValidationError extends Error {
  readonly errors: ValidationErrors

  constructor(message: string, errors: ValidationErrors) {
    super(message)
    this.name = 'UploadValidationError'
    this.errors = errors
  }
}

/** 413 et 429 : un message global, sans champ associé. */
export class UploadMessageError extends Error {
  constructor(message: string) {
    super(message)
    this.name = 'UploadMessageError'
  }
}

/** 401 : la session a déjà été purgée, il reste à rediriger vers la connexion. */
export class UploadUnauthenticatedError extends Error {
  constructor(message: string) {
    super(message)
    this.name = 'UploadUnauthenticatedError'
  }
}

/**
 * Un 413 peut être produit avant que l'application n'ait la main (limite du
 * serveur web), auquel cas le corps n'est pas du JSON : la lecture ne doit pas
 * transformer une erreur attendue en échec inattendu.
 */
async function readJson<T>(response: Response): Promise<T | null> {
  try {
    return (await response.json()) as T
  } catch {
    return null
  }
}

export const useFilesStore = defineStore('files', () => {
  const authStore = useAuthStore()

  async function upload(file: File, options: UploadOptions): Promise<UploadedFile> {
    const body = new FormData()
    body.append('file', file)
    if (options.password) {
      body.append('password', options.password)
    }
    body.append('expires_in_days', String(options.expiresInDays))

    const response = await fetch('/api/files', {
      method: 'POST',
      // Aucun Content-Type ici : le navigateur doit le poser lui-même pour y
      // joindre le boundary multipart. Le fixer à la main rend le corps
      // illisible côté serveur.
      headers: {
        Accept: 'application/json',
        Authorization: `Bearer ${authStore.token ?? ''}`,
      },
      body,
    })

    if (response.status === 201) {
      const data = (await response.json()) as UploadResponse
      return data.data
    }

    if (response.status === 401) {
      authStore.clearSession()
      throw new UploadUnauthenticatedError('Session expirée. Connecte-toi de nouveau.')
    }

    if (response.status === 422) {
      const data = await readJson<ValidationErrorResponse>(response)
      throw new UploadValidationError(data?.message ?? 'Erreur de validation.', data?.errors ?? {})
    }

    if (response.status === 413) {
      const data = await readJson<MessageResponse>(response)
      throw new UploadMessageError(data?.message ?? 'Le fichier envoyé est trop volumineux.')
    }

    if (response.status === 429) {
      const data = await readJson<MessageResponse>(response)
      throw new UploadMessageError(
        data?.message ?? 'Trop de téléversements. Réessaie dans quelques instants.',
      )
    }

    throw new Error(`Réponse inattendue du serveur (statut ${response.status}).`)
  }

  return { upload }
})
