import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia, type Pinia } from 'pinia'
import { createMemoryHistory, createRouter, type Router } from 'vue-router'

import UploadCard from '../UploadCard.vue'
import { TOKEN_STORAGE_KEY } from '@/stores/auth'

function jsonResponse(status: number, body: unknown): Response {
  return { status, json: () => Promise.resolve(body) } as unknown as Response
}

const uploadedFile = {
  id: 1,
  original_name: 'rapport.pdf',
  size: 2048,
  mime_type: 'application/pdf',
  protected: false,
  expires_at: '2026-08-28T10:00:00.000000Z',
  expired: false,
  link: 'https://datashare.test/l/token-abc',
  created_at: '2026-08-21T10:00:00.000000Z',
}

const fetchMock = vi.fn<typeof fetch>()
const writeTextMock = vi.fn<(text: string) => Promise<void>>()

let pinia: Pinia
let router: Router

function mountCard() {
  return mount(UploadCard, {
    global: { plugins: [pinia, router] },
    attachTo: document.body,
  })
}

/** jsdom n'alimente pas `input.files` : on l'injecte comme le ferait le navigateur. */
async function attachFile(wrapper: ReturnType<typeof mountCard>, file: File) {
  const input = wrapper.find('input[type="file"]')
  Object.defineProperty(input.element, 'files', { value: [file], configurable: true })
  await input.trigger('change')
}

function pdf(): File {
  return new File(['contenu'], 'rapport.pdf', { type: 'application/pdf' })
}

function oversizedFile(): File {
  const file = new File(['x'], 'archive.zip', { type: 'application/zip' })
  // 1 octet de trop : la borne est stricte, pas approchée.
  Object.defineProperty(file, 'size', { value: 2 ** 30 + 1 })
  return file
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
      { path: '/', component: { template: '<div />' } },
      { path: '/login', component: { template: '<div />' } },
    ],
  })
  await router.push('/')
  await router.isReady()
})

afterEach(() => {
  vi.unstubAllGlobals()
  vi.restoreAllMocks()
})

describe('UploadCard', () => {
  it('rend le titre "Ajouter un fichier" et les trois champs', () => {
    const wrapper = mountCard()

    expect(wrapper.find('h1').text()).toBe('Ajouter un fichier')
    expect(wrapper.find('#upload-file').attributes('type')).toBe('file')
    expect(wrapper.find('#upload-password').attributes('placeholder')).toBe('Optionnel')
    expect(wrapper.find('#upload-expiry').exists()).toBe(true)
  })

  it("propose 7 durées d'expiration et retient « Une semaine » par défaut", () => {
    const wrapper = mountCard()

    const options = wrapper.findAll('#upload-expiry option')
    expect(options).toHaveLength(7)
    expect(options[0]!.text()).toBe('Une journée')
    expect(options[6]!.text()).toBe('Une semaine')

    const select = wrapper.find('#upload-expiry').element as HTMLSelectElement
    expect(options[select.selectedIndex]!.text()).toBe('Une semaine')
  })

  // Le rognage du libellé se jouait en CSS, hors de portée de jsdom : ce test
  // garde au moins le chevron de la maquette, qui remplace la flèche native et
  // dont dépend la réserve horizontale du libellé.
  it('habille le select avec le chevron de la maquette, décoratif', () => {
    const wrapper = mountCard()

    const chevron = wrapper.find('.upload-expiry-chevron')
    expect(chevron.exists()).toBe(true)
    expect(chevron.attributes('aria-hidden')).toBe('true')
  })

  it('affiche le nom, la taille lisible et le bouton « Changer » une fois le fichier choisi', async () => {
    const wrapper = mountCard()

    await attachFile(wrapper, pdf())

    expect(wrapper.find('.upload-file-name').text()).toBe('rapport.pdf')
    expect(wrapper.find('.upload-file-size').text()).toBe('7 o')
    expect(wrapper.find('.upload-change-button').text()).toBe('Changer')
  })

  it("désactive « Téléverser » tant qu'aucun fichier n'est choisi", async () => {
    const wrapper = mountCard()

    expect(wrapper.find('.upload-submit').attributes('disabled')).toBeDefined()

    await attachFile(wrapper, pdf())

    expect(wrapper.find('.upload-submit').attributes('disabled')).toBeUndefined()
  })

  it('refuse un fichier de plus de 1 Go sans appeler le serveur', async () => {
    const wrapper = mountCard()

    await attachFile(wrapper, oversizedFile())

    expect(wrapper.find('#upload-file-error').text()).toBe(
      'La taille des fichiers est limitée à 1 Go',
    )
    expect(wrapper.find('.upload-submit').attributes('disabled')).toBeDefined()

    await wrapper.find('form').trigger('submit.prevent')
    await flushPromises()

    expect(fetchMock).not.toHaveBeenCalled()
  })

  it('refuse un mot de passe de moins de 6 caractères sans appeler le serveur', async () => {
    const wrapper = mountCard()

    await attachFile(wrapper, pdf())
    await wrapper.find('#upload-password').setValue('12345')
    await wrapper.find('form').trigger('submit.prevent')
    await flushPromises()

    expect(wrapper.find('#upload-password-error').text()).toBe(
      'Le mot de passe doit contenir au moins 6 caractères.',
    )
    expect(fetchMock).not.toHaveBeenCalled()
  })

  it('refuse un mot de passe de plus de 72 caractères sans appeler le serveur', async () => {
    const wrapper = mountCard()

    await attachFile(wrapper, pdf())
    await wrapper.find('#upload-password').setValue('a'.repeat(73))
    await wrapper.find('form').trigger('submit.prevent')
    await flushPromises()

    expect(wrapper.find('#upload-password-error').text()).toBe(
      'Le mot de passe ne doit pas dépasser 72 caractères.',
    )
    expect(fetchMock).not.toHaveBeenCalled()
  })

  it('avertit sans bloquer quand le fichier choisi porte une extension généralement refusée', async () => {
    const wrapper = mountCard()

    await attachFile(wrapper, new File(['x'], 'setup.exe', { type: 'application/x-msdownload' }))

    expect(wrapper.find('.app-callout--warning').text()).toContain(
      "Cette extension n'est généralement pas autorisée",
    )
    expect(wrapper.find('.upload-submit').attributes('disabled')).toBeUndefined()
  })

  it("n'avertit pas pour un fichier sans extension refusée", async () => {
    const wrapper = mountCard()

    await attachFile(wrapper, pdf())

    expect(wrapper.find('.app-callout--warning').exists()).toBe(false)
  })

  it('désactive le bouton pendant le téléversement', async () => {
    let resolveFetch: (response: Response) => void = () => {}
    fetchMock.mockReturnValue(
      new Promise<Response>((resolve) => {
        resolveFetch = resolve
      }),
    )
    const wrapper = mountCard()

    await attachFile(wrapper, pdf())
    await wrapper.find('form').trigger('submit.prevent')

    const submit = wrapper.find('.upload-submit')
    expect(submit.attributes('disabled')).toBeDefined()
    expect(submit.text()).toBe('Téléversement...')

    resolveFetch(jsonResponse(201, { data: uploadedFile }))
    await flushPromises()
  })

  it("bascule sur l'état final avec la durée retenue, le lien et la copie", async () => {
    fetchMock.mockResolvedValue(jsonResponse(201, { data: uploadedFile }))
    const wrapper = mountCard()

    await attachFile(wrapper, pdf())
    await wrapper.find('form').trigger('submit.prevent')
    await flushPromises()

    expect(wrapper.find('form').exists()).toBe(false)
    expect(wrapper.find('.upload-success-message').text()).toContain('une semaine')

    const link = wrapper.find('.upload-link')
    expect(link.attributes('href')).toBe('https://datashare.test/l/token-abc')
    expect(link.text()).toBe('https://datashare.test/l/token-abc')

    const copyButton = wrapper.find('.upload-copy-button')
    expect(copyButton.text()).toBe('Copier le lien')

    await copyButton.trigger('click')
    await flushPromises()

    expect(writeTextMock).toHaveBeenCalledWith('https://datashare.test/l/token-abc')
    expect(wrapper.find('.upload-copy-button').text()).toBe('Lien copié !')
  })

  it('expose une région role="status" annonçant le succès du téléversement', async () => {
    fetchMock.mockResolvedValue(jsonResponse(201, { data: uploadedFile }))
    const wrapper = mountCard()

    await attachFile(wrapper, pdf())
    await wrapper.find('form').trigger('submit.prevent')
    await flushPromises()

    const live = wrapper.find('[role="status"]')
    expect(live.attributes('aria-live')).toBe('polite')
    expect(live.text()).toBe('Félicitations, ton fichier est en ligne !')
  })

  it("mentionne la durée choisie quand elle n'est pas celle par défaut", async () => {
    fetchMock.mockResolvedValue(jsonResponse(201, { data: uploadedFile }))
    const wrapper = mountCard()

    await attachFile(wrapper, pdf())
    await wrapper.find('#upload-expiry').setValue('1')
    await wrapper.find('form').trigger('submit.prevent')
    await flushPromises()

    expect((fetchMock.mock.calls[0]![1]!.body as FormData).get('expires_in_days')).toBe('1')
    expect(wrapper.find('.upload-success-message').text()).toContain('une journée')
  })

  it("signale à l'utilisateur que la copie automatique a échoué", async () => {
    fetchMock.mockResolvedValue(jsonResponse(201, { data: uploadedFile }))
    writeTextMock.mockRejectedValue(new Error('refusé'))
    const wrapper = mountCard()

    await attachFile(wrapper, pdf())
    await wrapper.find('form').trigger('submit.prevent')
    await flushPromises()

    await wrapper.find('.upload-copy-button').trigger('click')
    await flushPromises()

    expect(wrapper.find('.upload-success .form-error').text()).toBe(
      'La copie a échoué, copie le lien manuellement.',
    )
  })

  it('affiche un 422 sous le champ fichier', async () => {
    fetchMock.mockResolvedValue(
      jsonResponse(422, {
        message: 'The given data was invalid.',
        errors: { file: ['Les fichiers de type « exe » ne sont pas autorisés.'] },
      }),
    )
    const wrapper = mountCard()

    await attachFile(wrapper, pdf())
    await wrapper.find('form').trigger('submit.prevent')
    await flushPromises()

    expect(wrapper.find('#upload-file-error').text()).toBe(
      'Les fichiers de type « exe » ne sont pas autorisés.',
    )
    expect(wrapper.find('form').exists()).toBe(true)
  })

  it('affiche un 413 et un 429 en message global', async () => {
    const wrapper = mountCard()
    await attachFile(wrapper, pdf())

    fetchMock.mockResolvedValueOnce(jsonResponse(413, { message: 'Fichier trop volumineux.' }))
    await wrapper.find('form').trigger('submit.prevent')
    await flushPromises()
    expect(wrapper.find('.form-error-global').text()).toBe('Fichier trop volumineux.')

    fetchMock.mockResolvedValueOnce(jsonResponse(429, { message: 'Trop de téléversements.' }))
    await wrapper.find('form').trigger('submit.prevent')
    await flushPromises()
    expect(wrapper.find('.form-error-global').text()).toBe('Trop de téléversements.')
  })

  it('redirige vers la connexion sur un 401, en mémorisant la page courante', async () => {
    fetchMock.mockResolvedValue(jsonResponse(401, { message: 'Unauthenticated.' }))
    const pushSpy = vi.spyOn(router, 'push')
    const wrapper = mountCard()

    await attachFile(wrapper, pdf())
    await wrapper.find('form').trigger('submit.prevent')
    await flushPromises()

    expect(pushSpy).toHaveBeenCalledWith({ path: '/login', query: { redirect: '/' } })
    expect(localStorage.getItem(TOKEN_STORAGE_KEY)).toBeNull()
  })
})
