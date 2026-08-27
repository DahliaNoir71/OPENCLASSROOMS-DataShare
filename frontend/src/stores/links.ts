import { defineStore } from 'pinia'

import { NETWORK_ERROR_MESSAGE } from '@/utils/network'
import { readJson } from '@/utils/readJson'

/** Les 5 champs de LinkMetadata (docs/openapi.yaml), renvoyés à plat. */
export interface LinkMetadata {
  original_name: string
  size: number
  mime_type: string
  protected: boolean
  expires_at: string
}

interface MessageResponse {
  message?: string
}

interface ValidationErrorResponse {
  message?: string
  errors?: Record<string, string[]>
}

/** 404 : token inconnu, mal formé, ou dont la ligne a été supprimée (US06). */
export class LinkNotFoundError extends Error {
  constructor(message: string) {
    super(message)
    this.name = 'LinkNotFoundError'
  }
}

/** 410 : lien échu, ou octets absents du disque. */
export class LinkGoneError extends Error {
  constructor(message: string) {
    super(message)
    this.name = 'LinkGoneError'
  }
}

/** 401 (téléchargement seul) : mot de passe absent ou incorrect. */
export class LinkPasswordError extends Error {
  constructor(message: string) {
    super(message)
    this.name = 'LinkPasswordError'
  }
}

/** 422 (téléchargement seul) : password non-string ou > 72 caractères. */
export class LinkValidationError extends Error {
  readonly errors: Record<string, string[]>

  constructor(message: string, errors: Record<string, string[]>) {
    super(message)
    this.name = 'LinkValidationError'
    this.errors = errors
  }
}

/** 429 : un message global, sans champ associé. */
export class LinkMessageError extends Error {
  constructor(message: string) {
    super(message)
    this.name = 'LinkMessageError'
  }
}

export const useLinksStore = defineStore('links', () => {
  async function metadata(token: string): Promise<LinkMetadata> {
    // Route publique : aucun Authorization, un lien de partage n'est pas lié
    // à une session.
    let response: Response
    try {
      response = await fetch(`/api/links/${encodeURIComponent(token)}`, {
        headers: { Accept: 'application/json' },
      })
    } catch {
      throw new LinkMessageError(NETWORK_ERROR_MESSAGE)
    }

    if (response.status === 200) {
      return (await response.json()) as LinkMetadata
    }

    if (response.status === 404) {
      const data = await readJson<MessageResponse>(response)
      throw new LinkNotFoundError(data?.message ?? 'Ce lien de téléchargement est invalide.')
    }

    if (response.status === 410) {
      const data = await readJson<MessageResponse>(response)
      throw new LinkGoneError(
        data?.message ?? "Ce lien a expiré : le fichier n'est plus disponible.",
      )
    }

    if (response.status === 429) {
      const data = await readJson<MessageResponse>(response)
      throw new LinkMessageError(
        data?.message ?? 'Trop de requêtes. Réessaie dans quelques instants.',
      )
    }

    throw new Error(`Réponse inattendue du serveur (statut ${response.status}).`)
  }

  async function download(token: string, password?: string): Promise<Blob> {
    let response: Response
    try {
      response = await fetch(`/api/links/${encodeURIComponent(token)}/download`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          Accept: 'application/json',
        },
        body: JSON.stringify(password ? { password } : {}),
      })
    } catch {
      throw new LinkMessageError(NETWORK_ERROR_MESSAGE)
    }

    if (response.status === 200) {
      return await response.blob()
    }

    if (response.status === 401) {
      const data = await readJson<MessageResponse>(response)
      throw new LinkPasswordError(data?.message ?? 'Mot de passe incorrect.')
    }

    if (response.status === 404) {
      const data = await readJson<MessageResponse>(response)
      throw new LinkNotFoundError(data?.message ?? 'Ce lien de téléchargement est invalide.')
    }

    if (response.status === 410) {
      const data = await readJson<MessageResponse>(response)
      throw new LinkGoneError(
        data?.message ?? "Ce lien a expiré : le fichier n'est plus disponible.",
      )
    }

    if (response.status === 422) {
      const data = await readJson<ValidationErrorResponse>(response)
      throw new LinkValidationError(data?.message ?? 'Erreur de validation.', data?.errors ?? {})
    }

    if (response.status === 429) {
      const data = await readJson<MessageResponse>(response)
      throw new LinkMessageError(
        data?.message ?? 'Trop de requêtes. Réessaie dans quelques instants.',
      )
    }

    throw new Error(`Réponse inattendue du serveur (statut ${response.status}).`)
  }

  return { metadata, download }
})
