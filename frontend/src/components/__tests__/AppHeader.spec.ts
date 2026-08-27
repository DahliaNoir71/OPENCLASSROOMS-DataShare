import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia, type Pinia } from 'pinia'
import { createMemoryHistory, createRouter, type Router } from 'vue-router'

import AppHeader from '../AppHeader.vue'
import { TOKEN_STORAGE_KEY, useAuthStore } from '@/stores/auth'

function jsonResponse(status: number, body: unknown): Response {
  return { status, json: () => Promise.resolve(body) } as unknown as Response
}

const fetchMock = vi.fn<typeof fetch>()

let pinia: Pinia
let router: Router

function mountHeader() {
  return mount(AppHeader, {
    global: { plugins: [pinia, router] },
  })
}

beforeEach(async () => {
  localStorage.clear()
  fetchMock.mockReset()
  vi.stubGlobal('fetch', fetchMock)
  pinia = createPinia()
  setActivePinia(pinia)
  router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/', component: { template: '<div />' } },
      { path: '/login', component: { template: '<div />' } },
      { path: '/mon-espace', component: { template: '<div />' } },
    ],
  })
  await router.push('/')
  await router.isReady()
})

afterEach(() => {
  vi.unstubAllGlobals()
})

describe('AppHeader', () => {
  it('propose « Se connecter » vers /login sans session', () => {
    const wrapper = mountHeader()

    const link = wrapper.find('.app-header-login')
    expect(link.text()).toBe('Se connecter')
    expect(link.attributes('href')).toBe('/login')
  })

  it('propose « Mon espace » vers /mon-espace pour un utilisateur connecté', () => {
    localStorage.setItem(TOKEN_STORAGE_KEY, 'jwt-test')

    const wrapper = mountHeader()

    const link = wrapper.find('.app-header-login')
    expect(link.text()).toBe('Mon espace')
    expect(link.attributes('href')).toBe('/mon-espace')
  })

  it("n'affiche pas le bouton de déconnexion sans session", () => {
    const wrapper = mountHeader()

    expect(wrapper.find('.app-header-logout').exists()).toBe(false)
  })

  it('affiche le bouton de déconnexion pour un utilisateur connecté', () => {
    localStorage.setItem(TOKEN_STORAGE_KEY, 'jwt-test')

    const wrapper = mountHeader()

    const button = wrapper.find('.app-header-logout')
    expect(button.exists()).toBe(true)
    expect(button.text()).toBe('Se déconnecter')
  })

  it('déconnecte et redirige vers / au clic', async () => {
    localStorage.setItem(TOKEN_STORAGE_KEY, 'jwt-test')
    fetchMock.mockResolvedValue(jsonResponse(200, {}))
    await router.push('/mon-espace')
    const wrapper = mountHeader()
    const store = useAuthStore()

    await wrapper.find('.app-header-logout').trigger('click')
    await flushPromises()

    expect(fetchMock).toHaveBeenCalledWith('/api/auth/logout', {
      method: 'POST',
      headers: {
        Accept: 'application/json',
        Authorization: 'Bearer jwt-test',
      },
    })
    expect(store.token).toBeNull()
    expect(router.currentRoute.value.path).toBe('/')
  })
})
