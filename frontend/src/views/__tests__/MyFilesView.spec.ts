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
  })
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
    expect(buttons.map((button) => button.text())).toEqual(['Tous', 'Actifs', 'Expiré'])

    await buttons[1]!.trigger('click')
    await flushPromises()

    expect(fetchMock).toHaveBeenCalledTimes(2)
    expect(fetchMock.mock.calls[1]![0]).toBe('/api/files?status=active&page=1')
    expect(buttons[1]!.classes()).toContain('status-switch-option--selected')
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

  it('affiche le message du serveur sur un 429', async () => {
    fetchMock.mockResolvedValue(jsonResponse(429, { message: 'Trop de requêtes.' }))

    const wrapper = mountView()
    await flushPromises()

    expect(wrapper.find('.form-error-global').text()).toBe('Trop de requêtes.')
  })

  it('redirige vers la connexion si la session est révoquée pendant la consultation', async () => {
    fetchMock.mockResolvedValue(jsonResponse(401, { message: 'Unauthenticated.' }))
    const pushSpy = vi.spyOn(router, 'push')

    mountView()
    await flushPromises()

    expect(pushSpy).toHaveBeenCalledWith({ path: '/login', query: { redirect: '/mon-espace' } })
  })

  describe('suppression', () => {
    it('demande confirmation avant de supprimer et annule si elle est refusée', async () => {
      fetchMock.mockResolvedValue(jsonResponse(200, pageOf([activeFile()])))
      const confirmSpy = vi.spyOn(window, 'confirm').mockReturnValue(false)

      const wrapper = mountView()
      await flushPromises()

      await wrapper.find('.file-row-delete-button').trigger('click')
      await flushPromises()

      expect(confirmSpy).toHaveBeenCalledWith(
        'Supprimer définitivement « rapport.pdf » ? Cette action est irréversible.',
      )
      expect(fetchMock).toHaveBeenCalledTimes(1)
    })

    it('supprime le fichier confirmé puis rafraîchit la liste', async () => {
      fetchMock.mockResolvedValueOnce(jsonResponse(200, pageOf([activeFile()])))
      fetchMock.mockResolvedValueOnce(jsonResponse(204, {}))
      fetchMock.mockResolvedValueOnce(jsonResponse(200, pageOf([])))
      vi.spyOn(window, 'confirm').mockReturnValue(true)

      const wrapper = mountView()
      await flushPromises()

      await wrapper.find('.file-row-delete-button').trigger('click')
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
      vi.spyOn(window, 'confirm').mockReturnValue(true)

      const wrapper = mountView()
      await flushPromises()

      await wrapper.find('.file-row-delete-button').trigger('click')
      await flushPromises()

      expect(fetchMock).toHaveBeenCalledTimes(3)
      expect(wrapper.find('.form-error-global').exists()).toBe(false)
      expect(wrapper.text()).toContain('Aucun fichier à afficher.')
    })

    it('affiche le message du serveur si la suppression échoue sur un 429', async () => {
      fetchMock.mockResolvedValueOnce(jsonResponse(200, pageOf([activeFile()])))
      fetchMock.mockResolvedValueOnce(jsonResponse(429, { message: 'Trop de requêtes.' }))
      vi.spyOn(window, 'confirm').mockReturnValue(true)

      const wrapper = mountView()
      await flushPromises()

      await wrapper.find('.file-row-delete-button').trigger('click')
      await flushPromises()

      expect(wrapper.find('.form-error-global').text()).toBe('Trop de requêtes.')
    })

    it('redirige vers la connexion si la session est révoquée pendant la suppression', async () => {
      fetchMock.mockResolvedValueOnce(jsonResponse(200, pageOf([activeFile()])))
      fetchMock.mockResolvedValueOnce(jsonResponse(401, { message: 'Unauthenticated.' }))
      vi.spyOn(window, 'confirm').mockReturnValue(true)
      const pushSpy = vi.spyOn(router, 'push')

      const wrapper = mountView()
      await flushPromises()

      await wrapper.find('.file-row-delete-button').trigger('click')
      await flushPromises()

      expect(pushSpy).toHaveBeenCalledWith({ path: '/login', query: { redirect: '/mon-espace' } })
    })
  })
})
