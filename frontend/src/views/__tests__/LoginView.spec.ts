import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia, type Pinia } from 'pinia'
import { createMemoryHistory, createRouter, type Router } from 'vue-router'

import LoginView from '../LoginView.vue'
import { TOKEN_STORAGE_KEY, useAuthStore } from '@/stores/auth'

function jsonResponse(status: number, body: unknown): Response {
  return { status, json: () => Promise.resolve(body) } as unknown as Response
}

const fetchMock = vi.fn<typeof fetch>()

let pinia: Pinia
let router: Router

function mountView() {
  return mount(LoginView, {
    global: { plugins: [pinia, router] },
  })
}

async function fillAndSubmit(
  wrapper: ReturnType<typeof mountView>,
  {
    email = 'user@example.com',
    password = 'motdepasse123',
  }: { email?: string; password?: string } = {},
) {
  await wrapper.find('#login-email').setValue(email)
  await wrapper.find('#login-password').setValue(password)
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
      { path: '/login', component: LoginView },
      { path: '/register', component: { template: '<div />' } },
    ],
  })
})

afterEach(() => {
  vi.unstubAllGlobals()
  vi.restoreAllMocks()
})

describe('LoginView', () => {
  it('rend le titre "Connexion"', () => {
    const wrapper = mountView()

    expect(wrapper.find('h1').text()).toBe('Connexion')
  })

  it('rend les champs, le lien vers /register et le bouton de soumission', () => {
    const wrapper = mountView()

    expect(wrapper.find('#login-email').attributes('type')).toBe('email')
    expect(wrapper.find('#login-password').attributes('type')).toBe('password')
    expect(wrapper.find('#login-password').attributes('autocomplete')).toBe('current-password')

    const link = wrapper.find('.login-register-link')
    expect(link.exists()).toBe(true)
    expect(link.attributes('href')).toBe('/register')

    const submit = wrapper.find('.login-submit')
    expect(submit.exists()).toBe(true)
    expect(submit.text()).toBe('Connexion')
  })

  it("affiche une erreur et n'appelle pas login si l'email est invalide", async () => {
    const loginSpy = vi.spyOn(useAuthStore(), 'login')
    const wrapper = mountView()

    await fillAndSubmit(wrapper, { email: 'pas-un-email' })

    const error = wrapper.find('#login-email-error')
    expect(error.exists()).toBe(true)
    expect(error.text()).toBe("Le format de l'email est invalide.")
    expect(loginSpy).not.toHaveBeenCalled()
    expect(fetchMock).not.toHaveBeenCalled()
  })

  it("affiche une erreur et n'appelle pas login si le mot de passe est vide", async () => {
    const loginSpy = vi.spyOn(useAuthStore(), 'login')
    const wrapper = mountView()

    await fillAndSubmit(wrapper, { password: '' })

    const error = wrapper.find('#login-password-error')
    expect(error.exists()).toBe(true)
    expect(error.text()).toBe('Le mot de passe est requis.')
    expect(loginSpy).not.toHaveBeenCalled()
  })

  it('appelle login et redirige vers "/" quand le formulaire est valide', async () => {
    fetchMock.mockResolvedValue(
      jsonResponse(200, { token: 'jwt-test', user: { id: 1, email: 'user@example.com' } }),
    )
    const loginSpy = vi.spyOn(useAuthStore(), 'login')
    const pushSpy = vi.spyOn(router, 'push')
    const wrapper = mountView()

    await fillAndSubmit(wrapper)

    expect(loginSpy).toHaveBeenCalledTimes(1)
    expect(loginSpy).toHaveBeenCalledWith('user@example.com', 'motdepasse123')
    expect(pushSpy).toHaveBeenCalledWith('/')
  })

  it('affiche le message global de l\'API et ne stocke aucun token sur un 401', async () => {
    fetchMock.mockResolvedValue(jsonResponse(401, { message: 'Identifiants invalides.' }))
    const wrapper = mountView()

    await fillAndSubmit(wrapper)

    const error = wrapper.find('.form-error-global')
    expect(error.exists()).toBe(true)
    expect(error.text()).toBe('Identifiants invalides.')
    expect(useAuthStore().token).toBeNull()
    expect(localStorage.getItem(TOKEN_STORAGE_KEY)).toBeNull()
  })

  it("affiche le message global de l'API sur un 429", async () => {
    fetchMock.mockResolvedValue(jsonResponse(429, { message: 'Trop de tentatives.' }))
    const wrapper = mountView()

    await fillAndSubmit(wrapper)

    expect(wrapper.find('.form-error-global').text()).toBe('Trop de tentatives.')
  })

  it('mappe les erreurs 422 sur les champs correspondants', async () => {
    fetchMock.mockResolvedValue(
      jsonResponse(422, {
        message: 'The given data was invalid.',
        errors: {
          email: ["Le champ email n'est pas une adresse valide."],
          password: ['Le champ mot de passe est obligatoire.'],
        },
      }),
    )
    const wrapper = mountView()

    await fillAndSubmit(wrapper)

    expect(wrapper.find('#login-email-error').text()).toBe(
      "Le champ email n'est pas une adresse valide.",
    )
    expect(wrapper.find('#login-password-error').text()).toBe(
      'Le champ mot de passe est obligatoire.',
    )
  })

  it('désactive le bouton pendant la requête puis le réactive', async () => {
    let resolveFetch: (response: Response) => void = () => {}
    fetchMock.mockReturnValue(
      new Promise<Response>((resolve) => {
        resolveFetch = resolve
      }),
    )
    const wrapper = mountView()

    await wrapper.find('#login-email').setValue('user@example.com')
    await wrapper.find('#login-password').setValue('motdepasse123')
    await wrapper.find('form').trigger('submit.prevent')

    expect(wrapper.find('.login-submit').attributes('disabled')).toBeDefined()

    resolveFetch(jsonResponse(200, { token: 'jwt-test', user: { id: 1, email: 'user@example.com' } }))
    await flushPromises()

    expect(wrapper.find('.login-submit').attributes('disabled')).toBeUndefined()
  })
})
