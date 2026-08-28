import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia, type Pinia } from 'pinia'
import { createMemoryHistory, createRouter, type Router } from 'vue-router'

import UploadCard from '../UploadCard.vue'
import { TOKEN_STORAGE_KEY } from '@/stores/auth'

/** Fausse XHR pilotable à la main : UploadCard passe par `filesStore.upload()`, en XHR. */
class FakeXMLHttpRequest {
  static instances: FakeXMLHttpRequest[] = []

  method = ''
  url = ''
  status = 0
  responseText = ''
  headers: Record<string, string> = {}
  body: unknown
  upload: { onprogress: ((event: ProgressEvent) => void) | null } = { onprogress: null }
  onload: (() => void) | null = null
  onerror: (() => void) | null = null

  open(method: string, url: string): void {
    this.method = method
    this.url = url
  }

  setRequestHeader(key: string, value: string): void {
    this.headers[key] = value
  }

  send(body: unknown): void {
    this.body = body
    FakeXMLHttpRequest.instances.push(this)
  }

  respond(status: number, responseText: string): void {
    this.status = status
    this.responseText = responseText
    this.onload?.()
  }

  progress(loaded: number, total: number, lengthComputable = true): void {
    this.upload.onprogress?.({ loaded, total, lengthComputable } as ProgressEvent)
  }

  fail(): void {
    this.onerror?.()
  }
}

function lastXhr(): FakeXMLHttpRequest {
  return FakeXMLHttpRequest.instances[FakeXMLHttpRequest.instances.length - 1]!
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
  writeTextMock.mockReset()
  writeTextMock.mockResolvedValue(undefined)
  FakeXMLHttpRequest.instances = []
  vi.stubGlobal('XMLHttpRequest', FakeXMLHttpRequest)
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

  it('donne un nom accessible complet au bouton « Changer »', async () => {
    const wrapper = mountCard()

    await attachFile(wrapper, pdf())

    expect(wrapper.find('.upload-change-button').attributes('aria-label')).toBe(
      'Changer de fichier',
    )
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

    expect(FakeXMLHttpRequest.instances).toHaveLength(0)
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
    expect(FakeXMLHttpRequest.instances).toHaveLength(0)
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
    expect(FakeXMLHttpRequest.instances).toHaveLength(0)
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
    const wrapper = mountCard()

    await attachFile(wrapper, pdf())
    await wrapper.find('form').trigger('submit.prevent')

    const submit = wrapper.find('.upload-submit')
    expect(submit.attributes('disabled')).toBeDefined()
    expect(submit.text()).toBe('Téléversement...')

    lastXhr().respond(201, JSON.stringify({ data: uploadedFile }))
    await flushPromises()
  })

  it('affiche une barre de progression avec le pourcentage annoncé par le callback', async () => {
    const wrapper = mountCard()

    await attachFile(wrapper, pdf())
    await wrapper.find('form').trigger('submit.prevent')

    const xhr = lastXhr()
    xhr.progress(42, 100)
    await flushPromises()

    const progressbar = wrapper.find('[role="progressbar"]')
    expect(progressbar.attributes('aria-valuemin')).toBe('0')
    expect(progressbar.attributes('aria-valuemax')).toBe('100')
    expect(progressbar.attributes('aria-valuenow')).toBe('42')
    expect(wrapper.find('.upload-progress-label').text()).toBe('42 %')

    xhr.respond(201, JSON.stringify({ data: uploadedFile }))
    await flushPromises()
  })

  it('bascule sur « Traitement... » une fois la progression à 100 %, réponse serveur non reçue', async () => {
    const wrapper = mountCard()

    await attachFile(wrapper, pdf())
    await wrapper.find('form').trigger('submit.prevent')

    const xhr = lastXhr()
    xhr.progress(100, 100)
    await flushPromises()

    const submit = wrapper.find('.upload-submit')
    expect(submit.text()).toBe('Traitement...')
    expect(submit.attributes('disabled')).toBeDefined()
    expect(wrapper.find('[role="progressbar"]').attributes('aria-valuenow')).toBe('100')

    const live = wrapper.find('[role="status"]')
    expect(live.text()).toBe('Fichier envoyé, traitement en cours...')

    xhr.respond(201, JSON.stringify({ data: uploadedFile }))
    await flushPromises()
  })

  it("bascule sur l'état final avec la durée retenue, le lien et la copie", async () => {
    const wrapper = mountCard()

    await attachFile(wrapper, pdf())
    await wrapper.find('form').trigger('submit.prevent')
    lastXhr().respond(201, JSON.stringify({ data: uploadedFile }))
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
    const wrapper = mountCard()

    await attachFile(wrapper, pdf())
    await wrapper.find('form').trigger('submit.prevent')
    lastXhr().respond(201, JSON.stringify({ data: uploadedFile }))
    await flushPromises()

    const live = wrapper.find('[role="status"]')
    expect(live.attributes('aria-live')).toBe('polite')
    expect(live.text()).toBe('Félicitations, votre fichier est en ligne !')
  })

  it("mentionne la durée choisie quand elle n'est pas celle par défaut", async () => {
    const wrapper = mountCard()

    await attachFile(wrapper, pdf())
    await wrapper.find('#upload-expiry').setValue('1')
    await wrapper.find('form').trigger('submit.prevent')
    const xhr = lastXhr()
    expect((xhr.body as FormData).get('expires_in_days')).toBe('1')
    xhr.respond(201, JSON.stringify({ data: uploadedFile }))
    await flushPromises()

    expect(wrapper.find('.upload-success-message').text()).toContain('une journée')
  })

  it("signale à l'utilisateur que la copie automatique a échoué", async () => {
    writeTextMock.mockRejectedValue(new Error('refusé'))
    const wrapper = mountCard()

    await attachFile(wrapper, pdf())
    await wrapper.find('form').trigger('submit.prevent')
    lastXhr().respond(201, JSON.stringify({ data: uploadedFile }))
    await flushPromises()

    await wrapper.find('.upload-copy-button').trigger('click')
    await flushPromises()

    expect(wrapper.find('.upload-success .form-error').text()).toBe(
      'La copie a échoué, copiez le lien manuellement.',
    )
  })

  it('affiche un 422 sous le champ fichier', async () => {
    const wrapper = mountCard()

    await attachFile(wrapper, pdf())
    await wrapper.find('form').trigger('submit.prevent')
    lastXhr().respond(
      422,
      JSON.stringify({
        message: 'The given data was invalid.',
        errors: { file: ['Les fichiers de type « exe » ne sont pas autorisés.'] },
      }),
    )
    await flushPromises()

    expect(wrapper.find('#upload-file-error').text()).toBe(
      'Les fichiers de type « exe » ne sont pas autorisés.',
    )
    expect(wrapper.find('form').exists()).toBe(true)
  })

  it('affiche un 413 et un 429 en message global', async () => {
    const wrapper = mountCard()
    await attachFile(wrapper, pdf())

    await wrapper.find('form').trigger('submit.prevent')
    lastXhr().respond(413, JSON.stringify({ message: 'Fichier trop volumineux.' }))
    await flushPromises()
    expect(wrapper.find('.form-error-global').text()).toBe('Fichier trop volumineux.')

    await wrapper.find('form').trigger('submit.prevent')
    lastXhr().respond(429, JSON.stringify({ message: 'Trop de téléversements.' }))
    await flushPromises()
    expect(wrapper.find('.form-error-global').text()).toBe('Trop de téléversements.')
  })

  it('redirige vers la connexion sur un 401, en mémorisant la page courante', async () => {
    const pushSpy = vi.spyOn(router, 'push')
    const wrapper = mountCard()

    await attachFile(wrapper, pdf())
    await wrapper.find('form').trigger('submit.prevent')
    lastXhr().respond(401, JSON.stringify({ message: 'Unauthenticated.' }))
    await flushPromises()

    expect(pushSpy).toHaveBeenCalledWith({ path: '/login', query: { redirect: '/' } })
    expect(localStorage.getItem(TOKEN_STORAGE_KEY)).toBeNull()
  })
})
