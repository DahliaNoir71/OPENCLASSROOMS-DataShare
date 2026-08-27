import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia, type Pinia } from 'pinia'
import { createMemoryHistory, createRouter, type Router } from 'vue-router'

import RegisterView from '../RegisterView.vue'
import { TOKEN_STORAGE_KEY, useAuthStore } from '@/stores/auth'

function jsonResponse(status: number, body: unknown): Response {
  return { status, json: () => Promise.resolve(body) } as unknown as Response
}

const fetchMock = vi.fn<typeof fetch>()

let pinia: Pinia
let router: Router

function mountView() {
  return mount(RegisterView, {
    global: { plugins: [pinia, router] },
  })
}

async function fillAndSubmit(
  wrapper: ReturnType<typeof mountView>,
  {
    email = 'user@example.com',
    password = 'motdepasse123',
    confirmation = 'motdepasse123',
  }: { email?: string; password?: string; confirmation?: string } = {},
) {
  await wrapper.find('#register-email').setValue(email)
  await wrapper.find('#register-password').setValue(password)
  await wrapper.find('#register-password-confirmation').setValue(confirmation)
  await wrapper.find('form').trigger('submit.prevent')
  await flushPromises()
}

beforeEach(() => {
  localStorage.clear()
  fetchMock.mockReset()
  vi.stubGlobal('fetch', fetchMock)
  pinia = createPinia()
  setActivePinia(pinia)
  router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/', component: { template: '<div />' } },
      { path: '/register', component: RegisterView },
      { path: '/login', component: { template: '<div />' } },
      { path: '/mon-espace', component: { template: '<div />' } },
    ],
  })
})

afterEach(() => {
  vi.unstubAllGlobals()
  vi.restoreAllMocks()
})

describe('RegisterView', () => {
  it('rend le titre "Créer un compte"', () => {
    const wrapper = mountView()

    expect(wrapper.find('h1').text()).toBe('Créer un compte')
  })

  it("affiche une erreur et n'appelle pas register si l'email est invalide", async () => {
    const registerSpy = vi.spyOn(useAuthStore(), 'register')
    const wrapper = mountView()

    await fillAndSubmit(wrapper, { email: 'pas-un-email' })

    const error = wrapper.find('#register-email-error')
    expect(error.exists()).toBe(true)
    expect(error.text()).toBe("Le format de l'email est invalide.")
    expect(registerSpy).not.toHaveBeenCalled()
  })

  it("affiche une erreur et n'appelle pas register si le mot de passe fait moins de 8 caractères", async () => {
    const registerSpy = vi.spyOn(useAuthStore(), 'register')
    const wrapper = mountView()

    await fillAndSubmit(wrapper, { password: 'court12', confirmation: 'court12' })

    const error = wrapper.find('#register-password-error')
    expect(error.exists()).toBe(true)
    expect(error.text()).toBe('Le mot de passe doit contenir au moins 8 caractères.')
    expect(registerSpy).not.toHaveBeenCalled()
  })

  it("affiche une erreur et n'appelle pas register si la vérification diffère du mot de passe", async () => {
    const registerSpy = vi.spyOn(useAuthStore(), 'register')
    const wrapper = mountView()

    await fillAndSubmit(wrapper, { password: 'motdepasse123', confirmation: 'autrechose456' })

    const error = wrapper.find('#register-password-confirmation-error')
    expect(error.exists()).toBe(true)
    expect(error.text()).toBe('La vérification ne correspond pas au mot de passe.')
    expect(registerSpy).not.toHaveBeenCalled()
  })

  it('appelle register avec les valeurs exactes quand le formulaire est valide', async () => {
    fetchMock.mockResolvedValue(
      jsonResponse(201, { token: 'jwt-test', user: { id: 1, email: 'user@example.com' } }),
    )
    const registerSpy = vi.spyOn(useAuthStore(), 'register')
    const wrapper = mountView()

    await fillAndSubmit(wrapper, {
      email: 'user@example.com',
      password: 'motdepasse123',
      confirmation: 'motdepasse123',
    })

    expect(registerSpy).toHaveBeenCalledTimes(1)
    expect(registerSpy).toHaveBeenCalledWith('user@example.com', 'motdepasse123', 'motdepasse123')
  })

  it("affiche le message d'erreur 422 sous le champ email", async () => {
    fetchMock.mockResolvedValue(
      jsonResponse(422, {
        message: 'The given data was invalid.',
        errors: { email: ['Cet email est déjà utilisé.'] },
      }),
    )
    const wrapper = mountView()

    await fillAndSubmit(wrapper)

    const error = wrapper.find('#register-email-error')
    expect(error.exists()).toBe(true)
    expect(error.text()).toBe('Cet email est déjà utilisé.')
  })

  it('affiche un message stable quand le réseau échoue', async () => {
    fetchMock.mockRejectedValue(new TypeError('Failed to fetch'))
    const wrapper = mountView()

    await fillAndSubmit(wrapper)

    expect(wrapper.find('.form-error-global').text()).toBe(
      'Connexion au serveur impossible. Vérifie ta connexion et réessaie.',
    )
  })

  it('persiste le token et déclenche la redirection vers "/" en cas de succès', async () => {
    fetchMock.mockResolvedValue(
      jsonResponse(201, { token: 'jwt-test', user: { id: 1, email: 'user@example.com' } }),
    )
    const pushSpy = vi.spyOn(router, 'push')
    const wrapper = mountView()

    await fillAndSubmit(wrapper)

    expect(localStorage.getItem(TOKEN_STORAGE_KEY)).toBe('jwt-test')
    expect(pushSpy).toHaveBeenCalledWith('/')
  })
})
