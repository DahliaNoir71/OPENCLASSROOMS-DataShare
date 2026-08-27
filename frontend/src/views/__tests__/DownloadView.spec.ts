import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia, type Pinia } from 'pinia'
import { createMemoryHistory, createRouter, type Router } from 'vue-router'

import DownloadView from '../DownloadView.vue'

vi.mock('@/utils/saveBlob', () => ({ saveBlob: vi.fn<(blob: Blob, filename: string) => void>() }))

import { saveBlob } from '@/utils/saveBlob'

function jsonResponse(status: number, body: unknown): Response {
  return { status, json: () => Promise.resolve(body) } as unknown as Response
}

function blobResponse(status: number, blob: Blob): Response {
  return { status, blob: () => Promise.resolve(blob) } as unknown as Response
}

function metadata(overrides: Partial<Record<string, unknown>> = {}) {
  return {
    original_name: 'rapport.pdf',
    size: 2048,
    mime_type: 'application/pdf',
    protected: false,
    expires_at: '2026-08-30T00:00:00.000Z',
    ...overrides,
  }
}

const fetchMock = vi.fn<typeof fetch>()

let pinia: Pinia
let router: Router

function mountView() {
  return mount(DownloadView, {
    global: { plugins: [pinia, router] },
    attachTo: document.body,
  })
}

beforeEach(async () => {
  vi.useFakeTimers()
  vi.setSystemTime(new Date('2026-08-27T00:00:00.000Z'))

  localStorage.clear()
  fetchMock.mockReset()
  vi.stubGlobal('fetch', fetchMock)
  vi.mocked(saveBlob).mockReset()

  pinia = createPinia()
  setActivePinia(pinia)
  router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/l/:token', component: DownloadView },
      { path: '/login', component: { template: '<div />' } },
      { path: '/mon-espace', component: { template: '<div />' } },
    ],
  })
  await router.push('/l/token-abc')
  await router.isReady()
})

afterEach(() => {
  vi.useRealTimers()
  vi.unstubAllGlobals()
  vi.restoreAllMocks()
  document.body.innerHTML = ''
})

describe('DownloadView', () => {
  it('interroge /api/links/{token} au montage avec le token de la route et affiche « Chargement… »', async () => {
    fetchMock.mockResolvedValue(jsonResponse(200, metadata()))

    const wrapper = mountView()

    expect(wrapper.text()).toContain('Chargement…')
    expect(fetchMock.mock.calls[0]![0]).toBe('/api/links/token-abc')

    await flushPromises()
  })

  it('affiche le nom, le type, la taille et un bandeau info « expirera dans N jours »', async () => {
    fetchMock.mockResolvedValue(jsonResponse(200, metadata()))

    const wrapper = mountView()
    await flushPromises()

    expect(wrapper.find('.download-file-name').text()).toBe('rapport.pdf')
    expect(wrapper.find('.download-file-meta').text()).toBe('PDF · 2 Ko')
    expect(wrapper.find('.app-callout--info').text()).toBe('Ce fichier expirera dans 3 jours.')
    expect(wrapper.find('input[type="password"]').exists()).toBe(false)
    expect(wrapper.find('.download-submit').attributes('disabled')).toBeUndefined()
  })

  it('affiche un bandeau warning « expirera demain » à J-1', async () => {
    fetchMock.mockResolvedValue(
      jsonResponse(200, metadata({ expires_at: '2026-08-27T20:00:00.000Z' })),
    )

    const wrapper = mountView()
    await flushPromises()

    expect(wrapper.find('.app-callout--warning').text()).toBe('Ce fichier expirera demain.')
    expect(wrapper.find('.app-callout--info').exists()).toBe(false)
  })

  it('reste sur un bandeau info à J-2', async () => {
    fetchMock.mockResolvedValue(
      jsonResponse(200, metadata({ expires_at: '2026-08-29T00:00:00.000Z' })),
    )

    const wrapper = mountView()
    await flushPromises()

    expect(wrapper.find('.app-callout--info').exists()).toBe(true)
    expect(wrapper.find('.app-callout--warning').exists()).toBe(false)
  })

  it('affiche le champ mot de passe pour un fichier protégé et active le bouton dès la saisie', async () => {
    fetchMock.mockResolvedValue(jsonResponse(200, metadata({ protected: true })))

    const wrapper = mountView()
    await flushPromises()

    const input = wrapper.find('input[type="password"]')
    expect(input.exists()).toBe(true)
    expect(input.attributes('maxlength')).toBe('72')
    expect(wrapper.find('.download-submit').attributes('disabled')).toBeDefined()

    await input.setValue('motdepasse')

    expect(wrapper.find('.download-submit').attributes('disabled')).toBeUndefined()
  })

  it('affiche « Téléchargement... » pendant l’appel', async () => {
    fetchMock.mockResolvedValueOnce(jsonResponse(200, metadata()))
    let resolveDownload!: (value: Response) => void
    fetchMock.mockImplementationOnce(
      () =>
        new Promise((resolve) => {
          resolveDownload = resolve
        }),
    )

    const wrapper = mountView()
    await flushPromises()

    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(wrapper.find('.download-submit').text()).toBe('Téléchargement...')

    resolveDownload(blobResponse(200, new Blob(['contenu'])))
    await flushPromises()
  })

  it('télécharge, enregistre sous le nom d’origine, affiche le bandeau succès et neutralise le bouton', async () => {
    const blob = new Blob(['contenu'], { type: 'application/pdf' })
    fetchMock.mockResolvedValueOnce(jsonResponse(200, metadata({ protected: true })))
    fetchMock.mockResolvedValueOnce(blobResponse(200, blob))

    const wrapper = mountView()
    await flushPromises()

    await wrapper.find('input[type="password"]').setValue('motdepasse')
    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(fetchMock.mock.calls[1]![0]).toBe('/api/links/token-abc/download')
    expect(
      JSON.parse((fetchMock.mock.calls[1]![1] as RequestInit).body as string),
    ).toEqual({ password: 'motdepasse' })
    expect(saveBlob).toHaveBeenCalledWith(blob, 'rapport.pdf')
    expect(wrapper.text()).toContain('Le téléchargement a démarré.')
    expect(wrapper.find('.download-submit').attributes('disabled')).toBeDefined()
  })

  it('affiche un 401 sous le champ mot de passe sans quitter l’écran', async () => {
    fetchMock.mockResolvedValueOnce(jsonResponse(200, metadata({ protected: true })))
    fetchMock.mockResolvedValueOnce(jsonResponse(401, { message: 'Mot de passe incorrect.' }))

    const wrapper = mountView()
    await flushPromises()

    await wrapper.find('input[type="password"]').setValue('mauvais')
    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(wrapper.find('#download-password-error').text()).toBe('Mot de passe incorrect.')
    expect(wrapper.find('.download-file').exists()).toBe(true)
  })

  it('bascule sur l’écran erreur si le lien échoit entre l’affichage et le clic (410)', async () => {
    fetchMock.mockResolvedValueOnce(jsonResponse(200, metadata()))
    fetchMock.mockResolvedValueOnce(
      jsonResponse(410, { message: "Ce lien a expiré : le fichier n'est plus disponible." }),
    )

    const wrapper = mountView()
    await flushPromises()

    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(wrapper.find('.app-callout--error').text()).toBe(
      "Ce lien a expiré : le fichier n'est plus disponible.",
    )
    expect(wrapper.find('.download-file').exists()).toBe(false)
    expect(wrapper.find('.download-submit').exists()).toBe(false)
  })

  it('bascule sur l’écran erreur si le lien est supprimé entre-temps (404, US06)', async () => {
    fetchMock.mockResolvedValueOnce(jsonResponse(200, metadata()))
    fetchMock.mockResolvedValueOnce(
      jsonResponse(404, { message: 'Ce lien de téléchargement est invalide.' }),
    )

    const wrapper = mountView()
    await flushPromises()

    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(wrapper.find('.app-callout--error').text()).toBe(
      'Ce lien de téléchargement est invalide.',
    )
  })

  it('affiche le message serveur sous le formulaire sur un 429 au téléchargement', async () => {
    fetchMock.mockResolvedValueOnce(jsonResponse(200, metadata()))
    fetchMock.mockResolvedValueOnce(jsonResponse(429, { message: 'Trop de requêtes.' }))

    const wrapper = mountView()
    await flushPromises()

    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(wrapper.find('.form-error-global').text()).toBe('Trop de requêtes.')
    expect(wrapper.find('.download-file').exists()).toBe(true)
  })

  it('affiche un message générique sur un statut inattendu au téléchargement', async () => {
    fetchMock.mockResolvedValueOnce(jsonResponse(200, metadata()))
    fetchMock.mockResolvedValueOnce(jsonResponse(500, {}))

    const wrapper = mountView()
    await flushPromises()

    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(wrapper.find('.form-error-global').text()).toBe(
      'Une erreur est survenue. Veuillez réessayer plus tard.',
    )
  })

  it('affiche l’écran erreur avec le message du serveur sur un 410 au chargement', async () => {
    fetchMock.mockResolvedValue(
      jsonResponse(410, { message: "Ce lien a expiré : le fichier n'est plus disponible." }),
    )

    const wrapper = mountView()
    await flushPromises()

    expect(wrapper.find('.app-callout--error').text()).toBe(
      "Ce lien a expiré : le fichier n'est plus disponible.",
    )
    expect(wrapper.find('.download-file').exists()).toBe(false)
    expect(wrapper.find('.download-submit').exists()).toBe(false)
  })

  it('affiche l’écran erreur avec le message du serveur sur un 404 au chargement', async () => {
    fetchMock.mockResolvedValue(
      jsonResponse(404, { message: 'Ce lien de téléchargement est invalide.' }),
    )

    const wrapper = mountView()
    await flushPromises()

    expect(wrapper.find('.app-callout--error').text()).toBe(
      'Ce lien de téléchargement est invalide.',
    )
  })

  it('affiche le message serveur sur un 429 au chargement', async () => {
    fetchMock.mockResolvedValue(jsonResponse(429, { message: 'Trop de requêtes.' }))

    const wrapper = mountView()
    await flushPromises()

    expect(wrapper.find('.app-callout--error').text()).toBe('Trop de requêtes.')
  })

  it('déplace le focus sur le bandeau d’erreur quand la bascule provient d’un clic', async () => {
    fetchMock.mockResolvedValueOnce(jsonResponse(200, metadata()))
    fetchMock.mockResolvedValueOnce(
      jsonResponse(410, { message: "Ce lien a expiré : le fichier n'est plus disponible." }),
    )

    const wrapper = mountView()
    await flushPromises()

    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(document.activeElement).toBe(wrapper.find('.app-callout--error').element)
  })

  it('ne déplace pas le focus quand l’erreur vient du chargement initial', async () => {
    fetchMock.mockResolvedValue(
      jsonResponse(404, { message: 'Ce lien de téléchargement est invalide.' }),
    )

    const wrapper = mountView()
    await flushPromises()

    expect(document.activeElement).not.toBe(wrapper.find('.app-callout--error').element)
  })
})
