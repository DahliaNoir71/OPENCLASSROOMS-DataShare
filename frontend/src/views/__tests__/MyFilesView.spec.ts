import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia, type Pinia } from 'pinia'
import { createMemoryHistory, createRouter, type Router } from 'vue-router'

import MyFilesView from '../MyFilesView.vue'
import { TOKEN_STORAGE_KEY } from '@/stores/auth'

function jsonResponse(status: number, body: unknown): Response {
  return { status, json: () => Promise.resolve(body) } as unknown as Response
}

function activeFile(overrides: Partial<Record<string, unknown>> = {}) {
  return {
    id: 1,
    original_name: 'rapport.pdf',
    size: 2048,
    mime_type: 'application/pdf',
    protected: false,
    expires_at: '2026-09-04T10:00:00.000000Z',
    expired: false,
    link: 'https://datashare.test/l/token-abc',
    created_at: '2026-08-21T10:00:00.000000Z',
    ...overrides,
  }
}

function pageOf(
  files: ReturnType<typeof activeFile>[],
  overrides: Partial<Record<string, unknown>> = {},
) {
  return {
    data: files,
    links: { first: null, last: null, prev: null, next: null },
    meta: {
      current_page: 1,
      from: files.length > 0 ? 1 : null,
      last_page: 1,
      path: '/api/files',
      per_page: 25,
      to: files.length,
      total: files.length,
      ...overrides,
    },
  }
}

const fetchMock = vi.fn<typeof fetch>()
const writeTextMock = vi.fn<(text: string) => Promise<void>>()

let pinia: Pinia
let router: Router

function mountView() {
  return mount(MyFilesView, {
    global: { plugins: [pinia, router] },
    attachTo: document.body,
  })
}

// jsdom n'implémente pas showModal()/close() sur <dialog> : polyfill minimal
// pour pouvoir exercer l'ouverture/fermeture dans les tests (voir B24).
if (!HTMLDialogElement.prototype.showModal) {
  HTMLDialogElement.prototype.showModal = function (this: HTMLDialogElement) {
    this.setAttribute('open', '')
  }
}
if (!HTMLDialogElement.prototype.close) {
  HTMLDialogElement.prototype.close = function (this: HTMLDialogElement) {
    this.removeAttribute('open')
    this.dispatchEvent(new Event('close'))
  }
}

beforeEach(async () => {
  localStorage.clear()
  localStorage.setItem(TOKEN_STORAGE_KEY, 'jwt-test')
  fetchMock.mockReset()
  writeTextMock.mockReset()
  writeTextMock.mockResolvedValue(undefined)
  vi.stubGlobal('fetch', fetchMock)
  Object.defineProperty(navigator, 'clipboard', {
    value: { writeText: writeTextMock },
    configurable: true,
  })
  pinia = createPinia()
  setActivePinia(pinia)
  router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/mon-espace', component: MyFilesView },
      { path: '/login', component: { template: '<div />' } },
    ],
  })
  await router.push('/mon-espace')
  await router.isReady()
})

afterEach(() => {
  vi.unstubAllGlobals()
  vi.restoreAllMocks()
})

describe('MyFilesView', () => {
  it('redirige un visiteur non authentifié vers la connexion, retour prévu sur la page courante', async () => {
    localStorage.clear()
    const pushSpy = vi.spyOn(router, 'push')

    mountView()
    await flushPromises()

    expect(pushSpy).toHaveBeenCalledWith({ path: '/login', query: { redirect: '/mon-espace' } })
    expect(fetchMock).not.toHaveBeenCalled()
  })

  it('interroge /api/files au chargement et rend le titre « Mes fichiers »', async () => {
    fetchMock.mockResolvedValue(jsonResponse(200, pageOf([activeFile()])))

    const wrapper = mountView()
    await flushPromises()

    expect(wrapper.find('h1').text()).toBe('Mes fichiers')
    expect(fetchMock.mock.calls[0]![0]).toBe('/api/files?status=all&page=1')
  })

  it('affiche le nom, la taille lisible et la date d’expiration de chaque fichier', async () => {
    fetchMock.mockResolvedValue(jsonResponse(200, pageOf([activeFile()])))

    const wrapper = mountView()
    await flushPromises()

    expect(wrapper.find('.file-row-name').text()).toBe('rapport.pdf')
    expect(wrapper.find('.file-row-meta').text()).toContain('2 Ko')
    expect(wrapper.find('.file-row-meta').text()).toContain('4 septembre 2026')
  })

  it('affiche « Aucun fichier à afficher. » quand la page est vide', async () => {
    fetchMock.mockResolvedValue(jsonResponse(200, pageOf([])))

    const wrapper = mountView()
    await flushPromises()

    expect(wrapper.text()).toContain('Aucun fichier à afficher.')
    expect(wrapper.find('.file-row').exists()).toBe(false)
  })

  it('copie le lien de partage et affiche une confirmation', async () => {
    fetchMock.mockResolvedValue(jsonResponse(200, pageOf([activeFile()])))

    const wrapper = mountView()
    await flushPromises()

    const copyButton = wrapper.find('.file-row-copy-button')
    expect(copyButton.text()).toBe('Copier le lien')

    await copyButton.trigger('click')
    await flushPromises()

    expect(writeTextMock).toHaveBeenCalledWith('https://datashare.test/l/token-abc')
    expect(wrapper.find('.file-row-copy-button').text()).toBe('Lien copié !')
  })

  it('affiche un message quand la copie échoue, sans bloquer les autres lignes', async () => {
    fetchMock.mockResolvedValue(jsonResponse(200, pageOf([activeFile()])))
    writeTextMock.mockRejectedValue(new Error('denied'))

    const wrapper = mountView()
    await flushPromises()

    await wrapper.find('.file-row-copy-button').trigger('click')
    await flushPromises()

    expect(wrapper.find('.file-row-copy-error').text()).toBe(
      'La copie a échoué, copiez le lien manuellement.',
    )
    expect(wrapper.find('.file-row-copy-button').text()).toBe('Copier le lien')
  })

  it('affiche « Expiré » sans bouton de copie pour un fichier expiré', async () => {
    fetchMock.mockResolvedValue(jsonResponse(200, pageOf([activeFile({ id: 2, expired: true })])))

    const wrapper = mountView()
    await flushPromises()

    expect(wrapper.find('.file-row-expired').text()).toBe('Expiré')
    expect(wrapper.find('.file-row-copy-button').exists()).toBe(false)
  })

  it('change de filtre, revient à la page 1 et refait la requête avec le bon status', async () => {
    fetchMock.mockResolvedValue(jsonResponse(200, pageOf([activeFile()])))

    const wrapper = mountView()
    await flushPromises()

    const buttons = wrapper.findAll('.status-switch-option')
    expect(buttons.map((button) => button.text())).toEqual(['Tous', 'Actifs', 'Expirés'])

    await buttons[1]!.trigger('click')
    await flushPromises()

    expect(fetchMock).toHaveBeenCalledTimes(2)
    expect(fetchMock.mock.calls[1]![0]).toBe('/api/files?status=active&page=1')
    expect(buttons[1]!.classes()).toContain('status-switch-option--selected')
  })

  describe('navigation clavier du radiogroup (roving tabindex)', () => {
    it("n'expose qu'un seul arrêt de tabulation, sur l'option sélectionnée", async () => {
      fetchMock.mockResolvedValue(jsonResponse(200, pageOf([activeFile()])))

      const wrapper = mountView()
      await flushPromises()

      const buttons = wrapper.findAll('.status-switch-option')
      expect(buttons.map((button) => button.attributes('tabindex'))).toEqual(['0', '-1', '-1'])
    })

    it('ArrowRight sélectionne et déplace le focus vers l’option suivante, de façon cyclique', async () => {
      fetchMock.mockResolvedValue(jsonResponse(200, pageOf([activeFile()])))

      const wrapper = mountView()
      await flushPromises()

      const buttons = wrapper.findAll('.status-switch-option')

      await buttons[0]!.trigger('keydown', { key: 'ArrowRight' })
      await flushPromises()
      expect(buttons[1]!.classes()).toContain('status-switch-option--selected')
      expect(buttons.map((button) => button.attributes('tabindex'))).toEqual(['-1', '0', '-1'])
      expect(document.activeElement).toBe(buttons[1]!.element)

      await buttons[1]!.trigger('keydown', { key: 'ArrowRight' })
      await buttons[2]!.trigger('keydown', { key: 'ArrowRight' })
      await flushPromises()
      expect(buttons[0]!.classes()).toContain('status-switch-option--selected')
    })

    it('ArrowLeft sélectionne et déplace le focus vers l’option précédente, de façon cyclique', async () => {
      fetchMock.mockResolvedValue(jsonResponse(200, pageOf([activeFile()])))

      const wrapper = mountView()
      await flushPromises()

      const buttons = wrapper.findAll('.status-switch-option')

      await buttons[0]!.trigger('keydown', { key: 'ArrowLeft' })
      await flushPromises()

      expect(buttons[2]!.classes()).toContain('status-switch-option--selected')
      expect(document.activeElement).toBe(buttons[2]!.element)
    })
  })

  it('affiche un cadenas et son alternative accessible pour un fichier protégé', async () => {
    fetchMock.mockResolvedValue(jsonResponse(200, pageOf([activeFile({ protected: true })])))

    const wrapper = mountView()
    await flushPromises()

    expect(wrapper.find('.file-row-protected-icon').exists()).toBe(true)
    expect(wrapper.find('.file-row-name').text()).toContain('Protégé par mot de passe')
  })

  it("n'affiche aucun cadenas pour un fichier non protégé", async () => {
    fetchMock.mockResolvedValue(jsonResponse(200, pageOf([activeFile({ protected: false })])))

    const wrapper = mountView()
    await flushPromises()

    expect(wrapper.find('.file-row-protected-icon').exists()).toBe(false)
  })

  it('désactive Précédent sur la première page et Suivant sur la dernière', async () => {
    fetchMock.mockResolvedValue(
      jsonResponse(200, pageOf([activeFile()], { current_page: 1, last_page: 1 })),
    )

    const wrapper = mountView()
    await flushPromises()

    const [prev, next] = wrapper.findAll('.pagination-button')
    expect(prev!.attributes('disabled')).toBeDefined()
    expect(next!.attributes('disabled')).toBeDefined()
  })

  it('demande la page suivante quand elle est disponible', async () => {
    fetchMock.mockResolvedValueOnce(
      jsonResponse(200, {
        ...pageOf([activeFile()], { current_page: 1, last_page: 2 }),
        links: { first: null, last: null, prev: null, next: '/api/files?page=2' },
      }),
    )
    fetchMock.mockResolvedValueOnce(
      jsonResponse(200, pageOf([activeFile({ id: 2 })], { current_page: 2, last_page: 2 })),
    )

    const wrapper = mountView()
    await flushPromises()

    await wrapper.findAll('.pagination-button')[1]!.trigger('click')
    await flushPromises()

    expect(fetchMock.mock.calls[1]![0]).toBe('/api/files?status=all&page=2')
    expect(wrapper.find('.pagination-status').text()).toBe('Page 2 / 2')
  })

  describe('requêtes concurrentes', () => {
    it('conserve la liste affichée et signale aria-busy pendant un rafraîchissement (changement de filtre)', async () => {
      fetchMock.mockResolvedValueOnce(jsonResponse(200, pageOf([activeFile()])))
      let resolveSecond!: (response: Response) => void
      fetchMock.mockImplementationOnce(
        () =>
          new Promise<Response>((resolve) => {
            resolveSecond = resolve
          }),
      )

      const wrapper = mountView()
      await flushPromises()

      const buttons = wrapper.findAll('.status-switch-option')
      await buttons[1]!.trigger('click')
      await flushPromises()

      // Le rafraîchissement est en cours : la liste précédente reste affichée,
      // sans repasser par le texte plein écran « Chargement… ».
      expect(wrapper.find('.file-row-name').text()).toContain('rapport.pdf')
      expect(wrapper.find('.file-list-region').attributes('aria-busy')).toBe('true')
      expect(wrapper.find('.my-files-status-text').exists()).toBe(false)

      resolveSecond(jsonResponse(200, pageOf([activeFile({ id: 2, original_name: 'autre.pdf' })])))
      await flushPromises()

      expect(wrapper.find('.file-list-region').attributes('aria-busy')).toBeUndefined()
      expect(wrapper.find('.file-row-name').text()).toContain('autre.pdf')
    })

    it("annule la requête précédente lorsqu'un nouveau filtre est choisi avant sa résolution", async () => {
      let firstSignal: AbortSignal | undefined
      fetchMock.mockImplementationOnce((_input, init) => {
        firstSignal = (init as RequestInit).signal as AbortSignal
        return new Promise<Response>(() => {
          // Volontairement jamais résolue : seul l'abandon nous intéresse ici.
        })
      })
      fetchMock.mockResolvedValueOnce(jsonResponse(200, pageOf([activeFile()])))

      const wrapper = mountView()
      await flushPromises()

      expect(firstSignal?.aborted).toBe(false)

      const buttons = wrapper.findAll('.status-switch-option')
      await buttons[1]!.trigger('click')
      await flushPromises()

      expect(firstSignal?.aborted).toBe(true)
    })

    it("une requête A abandonnée pendant qu'une requête B plus récente aboutit ne modifie aucun état de B — pas même via le `finally`", async () => {
      let rejectA!: (reason: unknown) => void
      fetchMock.mockImplementationOnce(
        () =>
          new Promise<Response>((_resolve, reject) => {
            rejectA = reject
          }),
      )
      fetchMock.mockResolvedValueOnce(
        jsonResponse(200, pageOf([activeFile({ id: 2, original_name: 'B.pdf' })])),
      )

      const wrapper = mountView()
      await flushPromises()

      const buttons = wrapper.findAll('.status-switch-option')
      await buttons[1]!.trigger('click')
      await flushPromises()

      // B a déjà abouti : liste à jour, plus de chargement en cours.
      expect(wrapper.find('.file-row-name').text()).toContain('B.pdf')
      expect(wrapper.find('.file-list-region').attributes('aria-busy')).toBeUndefined()

      // A s'abandonne après coup (comportement réel de fetch() sur un signal
      // aborted) : son échec ne doit ni afficher d'erreur, ni rouvrir l'état
      // "chargement" de B, ni toucher la liste déjà affichée par B.
      rejectA(new DOMException('The operation was aborted.', 'AbortError'))
      await flushPromises()

      expect(wrapper.find('.form-error-global').exists()).toBe(false)
      expect(wrapper.find('.file-row-name').text()).toContain('B.pdf')
      expect(wrapper.find('.file-list-region').attributes('aria-busy')).toBeUndefined()
    })
  })

  it('affiche le message du serveur sur un 429', async () => {
    fetchMock.mockResolvedValue(jsonResponse(429, { message: 'Trop de requêtes.' }))

    const wrapper = mountView()
    await flushPromises()

    expect(wrapper.find('.form-error-global').text()).toBe('Trop de requêtes.')
  })

  it('retombe sur les valeurs par défaut du filtre et affiche un message sur un 422', async () => {
    fetchMock.mockResolvedValueOnce(
      jsonResponse(422, {
        message: 'Le filtre demandé est invalide.',
        errors: { status: ['Le filtre demandé est invalide.'] },
      }),
    )
    fetchMock.mockResolvedValueOnce(jsonResponse(200, pageOf([activeFile()])))

    const wrapper = mountView()
    await flushPromises()

    expect(fetchMock).toHaveBeenCalledTimes(2)
    expect(fetchMock.mock.calls[1]![0]).toBe('/api/files?status=all&page=1')
    expect(wrapper.find('.form-error-global').text()).toBe('Le filtre demandé est invalide.')
    expect(wrapper.find('.file-row-name').text()).toBe('rapport.pdf')
  })

  it('redirige vers la connexion si la session est révoquée pendant la consultation', async () => {
    fetchMock.mockResolvedValue(jsonResponse(401, { message: 'Unauthenticated.' }))
    const pushSpy = vi.spyOn(router, 'push')

    mountView()
    await flushPromises()

    expect(pushSpy).toHaveBeenCalledWith({ path: '/login', query: { redirect: '/mon-espace' } })
  })

  describe('suppression', () => {
    it('ouvre un dialogue de confirmation et annule sans appeler le serveur', async () => {
      fetchMock.mockResolvedValue(jsonResponse(200, pageOf([activeFile()])))

      const wrapper = mountView()
      await flushPromises()

      const deleteButton = wrapper.find('.file-row-delete-button')
      await deleteButton.trigger('click')

      const dialog = wrapper.find('.confirm-dialog')
      expect(dialog.exists()).toBe(true)
      expect(dialog.attributes('aria-modal')).toBe('true')
      expect(dialog.text()).toContain(
        'Supprimer définitivement « rapport.pdf » ? Cette action est irréversible.',
      )

      await wrapper.find('.confirm-dialog-cancel').trigger('click')
      await flushPromises()

      expect(fetchMock).toHaveBeenCalledTimes(1)
    })

    it('place le focus initial sur « Annuler » et le rend au bouton déclencheur à la fermeture', async () => {
      fetchMock.mockResolvedValue(jsonResponse(200, pageOf([activeFile()])))

      const wrapper = mountView()
      await flushPromises()

      const deleteButton = wrapper.find('.file-row-delete-button')
      expect(deleteButton.attributes('autofocus')).toBeUndefined()
      await deleteButton.trigger('click')

      expect(wrapper.find('.confirm-dialog-cancel').attributes('autofocus')).toBeDefined()

      const dialogElement = wrapper.find('.confirm-dialog').element as HTMLDialogElement
      dialogElement.dispatchEvent(new Event('close'))
      await flushPromises()

      expect(document.activeElement).toBe(deleteButton.element)
      wrapper.unmount()
    })

    it('supprime le fichier confirmé puis rafraîchit la liste', async () => {
      fetchMock.mockResolvedValueOnce(jsonResponse(200, pageOf([activeFile()])))
      fetchMock.mockResolvedValueOnce(jsonResponse(204, {}))
      fetchMock.mockResolvedValueOnce(jsonResponse(200, pageOf([])))

      const wrapper = mountView()
      await flushPromises()

      await wrapper.find('.file-row-delete-button').trigger('click')
      await wrapper.find('.confirm-dialog-confirm').trigger('click')
      await flushPromises()

      expect(fetchMock).toHaveBeenCalledTimes(3)
      expect(fetchMock.mock.calls[1]![0]).toBe('/api/files/1')
      expect(fetchMock.mock.calls[1]![1]).toMatchObject({ method: 'DELETE' })
      expect(fetchMock.mock.calls[2]![0]).toBe('/api/files?status=all&page=1')
      expect(wrapper.text()).toContain('Aucun fichier à afficher.')
    })

    it('un 404 à la suppression (liste périmée) déclenche un simple rechargement, pas une erreur', async () => {
      fetchMock.mockResolvedValueOnce(jsonResponse(200, pageOf([activeFile()])))
      fetchMock.mockResolvedValueOnce(jsonResponse(404, { message: 'Ce fichier est introuvable.' }))
      fetchMock.mockResolvedValueOnce(jsonResponse(200, pageOf([])))

      const wrapper = mountView()
      await flushPromises()

      await wrapper.find('.file-row-delete-button').trigger('click')
      await wrapper.find('.confirm-dialog-confirm').trigger('click')
      await flushPromises()

      expect(fetchMock).toHaveBeenCalledTimes(3)
      expect(wrapper.find('.form-error-global').exists()).toBe(false)
      expect(wrapper.text()).toContain('Aucun fichier à afficher.')
    })

    it('affiche le message du serveur si la suppression échoue sur un 429', async () => {
      fetchMock.mockResolvedValueOnce(jsonResponse(200, pageOf([activeFile()])))
      fetchMock.mockResolvedValueOnce(jsonResponse(429, { message: 'Trop de requêtes.' }))

      const wrapper = mountView()
      await flushPromises()

      await wrapper.find('.file-row-delete-button').trigger('click')
      await wrapper.find('.confirm-dialog-confirm').trigger('click')
      await flushPromises()

      expect(wrapper.find('.form-error-global').text()).toBe('Trop de requêtes.')
    })

    it('affiche un message et rafraîchit la liste sur un statut de suppression inattendu', async () => {
      fetchMock.mockResolvedValueOnce(jsonResponse(200, pageOf([activeFile()])))
      fetchMock.mockResolvedValueOnce(jsonResponse(500, {}))
      fetchMock.mockResolvedValueOnce(jsonResponse(200, pageOf([])))

      const wrapper = mountView()
      await flushPromises()

      await wrapper.find('.file-row-delete-button').trigger('click')
      await wrapper.find('.confirm-dialog-confirm').trigger('click')
      await flushPromises()

      expect(fetchMock).toHaveBeenCalledTimes(3)
      expect(wrapper.find('.form-error-global').text()).toBe(
        'Une erreur est survenue. Veuillez réessayer plus tard.',
      )
      expect(wrapper.text()).toContain('Aucun fichier à afficher.')
    })

    it('affiche le message du serveur, sans rafraîchir, quand la suppression échoue sur une panne réseau', async () => {
      fetchMock.mockResolvedValueOnce(jsonResponse(200, pageOf([activeFile()])))
      fetchMock.mockRejectedValueOnce(new TypeError('Failed to fetch'))

      const wrapper = mountView()
      await flushPromises()

      await wrapper.find('.file-row-delete-button').trigger('click')
      await wrapper.find('.confirm-dialog-confirm').trigger('click')
      await flushPromises()

      expect(fetchMock).toHaveBeenCalledTimes(2)
      expect(wrapper.find('.form-error-global').text()).toBe(
        'Connexion au serveur impossible. Vérifiez votre connexion et réessayez.',
      )
      expect(wrapper.find('.file-row-name').text()).toContain('rapport.pdf')
    })

    it('redirige vers la connexion si la session est révoquée pendant la suppression', async () => {
      fetchMock.mockResolvedValueOnce(jsonResponse(200, pageOf([activeFile()])))
      fetchMock.mockResolvedValueOnce(jsonResponse(401, { message: 'Unauthenticated.' }))
      const pushSpy = vi.spyOn(router, 'push')

      const wrapper = mountView()
      await flushPromises()

      await wrapper.find('.file-row-delete-button').trigger('click')
      await wrapper.find('.confirm-dialog-confirm').trigger('click')
      await flushPromises()

      expect(pushSpy).toHaveBeenCalledWith({ path: '/login', query: { redirect: '/mon-espace' } })
    })

    it('affiche « Suppression... » et neutralise le bouton pendant la requête', async () => {
      fetchMock.mockResolvedValueOnce(jsonResponse(200, pageOf([activeFile()])))
      let resolveDelete!: (response: Response) => void
      fetchMock.mockReturnValueOnce(
        new Promise<Response>((resolve) => {
          resolveDelete = resolve
        }),
      )

      const wrapper = mountView()
      await flushPromises()

      await wrapper.find('.file-row-delete-button').trigger('click')
      await wrapper.find('.confirm-dialog-confirm').trigger('click')
      await flushPromises()

      const deleteButton = wrapper.find('.file-row-delete-button')
      expect(deleteButton.attributes('disabled')).toBeDefined()
      expect(deleteButton.text()).toBe('Suppression...')

      resolveDelete(jsonResponse(204, {}))
      await flushPromises()
    })
  })

  describe('annonces accessibles', () => {
    it('expose une région role="status" annonçant le chargement puis le nombre de fichiers', async () => {
      fetchMock.mockResolvedValue(jsonResponse(200, pageOf([activeFile()])))

      const wrapper = mountView()
      await flushPromises()

      const live = wrapper.find('[role="status"]')
      expect(live.attributes('aria-live')).toBe('polite')
      expect(live.text()).toBe('1 fichier affiché.')
    })

    it('annonce l\'absence de fichiers', async () => {
      fetchMock.mockResolvedValue(jsonResponse(200, pageOf([])))

      const wrapper = mountView()
      await flushPromises()

      expect(wrapper.find('[role="status"]').text()).toBe('Aucun fichier à afficher.')
    })

    it('annonce la copie du lien', async () => {
      fetchMock.mockResolvedValue(jsonResponse(200, pageOf([activeFile()])))

      const wrapper = mountView()
      await flushPromises()

      await wrapper.find('.file-row-copy-button').trigger('click')
      await flushPromises()

      expect(wrapper.find('[role="status"]').text()).toBe('Lien copié !')
    })
  })
})
