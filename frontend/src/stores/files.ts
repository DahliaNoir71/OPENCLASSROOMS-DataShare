import { defineStore } from 'pinia'

import { NETWORK_ERROR_MESSAGE } from '@/utils/network'
import { readJson } from '@/utils/readJson'

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
  /** Pourcentage entier (0-100) de l'envoi, uniquement si l'événement le permet (`lengthComputable`). */
  onProgress?: (percent: number) => void
}

interface UploadResponse {
  data: UploadedFile
}

/** Filtre maquette « Tous / Actifs / Expiré » (docs/openapi.yaml, GET /files). */
export type FileStatus = 'all' | 'active' | 'expired'

export interface ListOptions {
  status?: FileStatus
  page?: number
  perPage?: number
  /** Annule la requête en cours — voir MyFilesView, qui abandonne toute liste périmée par un filtre/page plus récent. */
  signal?: AbortSignal
}

/** Enveloppe native de pagination Laravel (docs/openapi.yaml, FileListResponse). */
export interface PaginationLinks {
  first: string | null
  last: string | null
  prev: string | null
  next: string | null
}

export interface PaginationMeta {
  current_page: number
  from: number | null
  last_page: number
  path: string
  per_page: number
  to: number | null
  total: number
}

export interface FilesPage {
  data: UploadedFile[]
  links: PaginationLinks
  meta: PaginationMeta
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

/** 422 : status ou per_page invalide (le switch et la pagination pilotent ces valeurs eux-mêmes, mais un lien suivi tel quel peut porter une valeur périmée). */
export class ListValidationError extends Error {
  readonly errors: ValidationErrors

  constructor(message: string, errors: ValidationErrors) {
    super(message)
    this.name = 'ListValidationError'
    this.errors = errors
  }
}

/** 429 : un message global, sans champ associé. */
export class ListMessageError extends Error {
  constructor(message: string) {
    super(message)
    this.name = 'ListMessageError'
  }
}

/** 401 : la session a déjà été purgée, il reste à rediriger vers la connexion. */
export class ListUnauthenticatedError extends Error {
  constructor(message: string) {
    super(message)
    this.name = 'ListUnauthenticatedError'
  }
}

/** 429 : un message global, sans champ associé. */
export class RemoveMessageError extends Error {
  constructor(message: string) {
    super(message)
    this.name = 'RemoveMessageError'
  }
}

/** 401 : la session a déjà été purgée, il reste à rediriger vers la connexion. */
export class RemoveUnauthenticatedError extends Error {
  constructor(message: string) {
    super(message)
    this.name = 'RemoveUnauthenticatedError'
  }
}

/**
 * 404 : identifiant inexistant ou fichier d'un autre compte (docs/openapi.yaml)
 * — les deux répondent le même 404, aucun 403 n'existe. Côté SPA, le seul cas
 * réel est une liste périmée : le fichier a déjà été supprimé ailleurs.
 */
export class RemoveNotFoundError extends Error {
  constructor(message: string) {
    super(message)
    this.name = 'RemoveNotFoundError'
  }
}

/** Pendant de `readJson` pour un corps XHR déjà reçu en texte (413 avant l'application, par exemple). */
function readXhrJson<T>(xhr: XMLHttpRequest): T | null {
  try {
    return JSON.parse(xhr.responseText) as T
  } catch {
    return null
  }
}

export const useFilesStore = defineStore('files', () => {
  const authStore = useAuthStore()

  function upload(file: File, options: UploadOptions): Promise<UploadedFile> {
    const body = new FormData()
    body.append('file', file)
    if (options.password) {
      body.append('password', options.password)
    }
    body.append('expires_in_days', String(options.expiresInDays))

    return new Promise<UploadedFile>((resolve, reject) => {
      const xhr = new XMLHttpRequest()
      xhr.open('POST', '/api/files')
      // Aucun Content-Type ici : le navigateur doit le poser lui-même pour y
      // joindre le boundary multipart. Le fixer à la main rend le corps
      // illisible côté serveur.
      xhr.setRequestHeader('Accept', 'application/json')
      xhr.setRequestHeader('Authorization', `Bearer ${authStore.token ?? ''}`)

      xhr.upload.onprogress = (event: ProgressEvent) => {
        if (event.lengthComputable) {
          options.onProgress?.(Math.round((event.loaded / event.total) * 100))
        }
      }

      xhr.onerror = () => {
        reject(new UploadMessageError(NETWORK_ERROR_MESSAGE))
      }

      xhr.onload = () => {
        if (xhr.status === 201) {
          const data = JSON.parse(xhr.responseText) as UploadResponse
          resolve(data.data)
          return
        }

        if (xhr.status === 401) {
          authStore.clearSession()
          reject(new UploadUnauthenticatedError('Session expirée. Connectez-vous de nouveau.'))
          return
        }

        if (xhr.status === 422) {
          const data = readXhrJson<ValidationErrorResponse>(xhr)
          reject(
            new UploadValidationError(data?.message ?? 'Erreur de validation.', data?.errors ?? {}),
          )
          return
        }

        if (xhr.status === 413) {
          const data = readXhrJson<MessageResponse>(xhr)
          reject(new UploadMessageError(data?.message ?? 'Le fichier envoyé est trop volumineux.'))
          return
        }

        if (xhr.status === 429) {
          const data = readXhrJson<MessageResponse>(xhr)
          reject(
            new UploadMessageError(
              data?.message ?? 'Trop de téléversements. Réessayez dans quelques instants.',
            ),
          )
          return
        }

        reject(new Error(`Réponse inattendue du serveur (statut ${xhr.status}).`))
      }

      xhr.send(body)
    })
  }

  async function list(options: ListOptions = {}): Promise<FilesPage> {
    const params = new URLSearchParams()
    if (options.status) {
      params.set('status', options.status)
    }
    if (options.page) {
      params.set('page', String(options.page))
    }
    if (options.perPage) {
      params.set('per_page', String(options.perPage))
    }

    const query = params.toString()
    let response: Response
    try {
      response = await fetch(`/api/files${query ? `?${query}` : ''}`, {
        headers: {
          Accept: 'application/json',
          Authorization: `Bearer ${authStore.token ?? ''}`,
        },
        signal: options.signal,
      })
    } catch (error) {
      // Une requête abandonnée (AbortController) n'est pas une panne réseau :
      // on la laisse remonter telle quelle pour que l'appelant la distingue.
      if (error instanceof DOMException && error.name === 'AbortError') {
        throw error
      }
      throw new ListMessageError(NETWORK_ERROR_MESSAGE)
    }

    if (response.status === 200) {
      return (await response.json()) as FilesPage
    }

    if (response.status === 401) {
      authStore.clearSession()
      throw new ListUnauthenticatedError('Session expirée. Connectez-vous de nouveau.')
    }

    if (response.status === 422) {
      const data = await readJson<ValidationErrorResponse>(response)
      throw new ListValidationError(data?.message ?? 'Erreur de validation.', data?.errors ?? {})
    }

    if (response.status === 429) {
      const data = await readJson<MessageResponse>(response)
      throw new ListMessageError(
        data?.message ?? 'Trop de requêtes. Réessayez dans quelques instants.',
      )
    }

    throw new Error(`Réponse inattendue du serveur (statut ${response.status}).`)
  }

  async function remove(id: number): Promise<void> {
    let response: Response
    try {
      response = await fetch(`/api/files/${id}`, {
        method: 'DELETE',
        headers: {
          Accept: 'application/json',
          Authorization: `Bearer ${authStore.token ?? ''}`,
        },
      })
    } catch {
      throw new RemoveMessageError(NETWORK_ERROR_MESSAGE)
    }

    if (response.status === 204) {
      return
    }

    if (response.status === 401) {
      authStore.clearSession()
      throw new RemoveUnauthenticatedError('Session expirée. Connectez-vous de nouveau.')
    }

    if (response.status === 404) {
      const data = await readJson<MessageResponse>(response)
      throw new RemoveNotFoundError(data?.message ?? 'Ce fichier est introuvable.')
    }

    if (response.status === 429) {
      const data = await readJson<MessageResponse>(response)
      throw new RemoveMessageError(
        data?.message ?? 'Trop de requêtes. Réessayez dans quelques instants.',
      )
    }

    throw new Error(`Réponse inattendue du serveur (statut ${response.status}).`)
  }

  return { upload, list, remove }
})
