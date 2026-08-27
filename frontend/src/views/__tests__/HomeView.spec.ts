import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia, type Pinia } from 'pinia'
import { createMemoryHistory, createRouter, type Router } from 'vue-router'

import HomeView from '../HomeView.vue'
import { TOKEN_STORAGE_KEY } from '@/stores/auth'

let pinia: Pinia
let router: Router

function mountView() {
  return mount(HomeView, {
    global: { plugins: [pinia, router] },
  })
}

beforeEach(async () => {
  localStorage.clear()
  vi.stubGlobal('fetch', vi.fn<typeof fetch>())
  pinia = createPinia()
  setActivePinia(pinia)
  router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/', component: HomeView },
      { path: '/login', component: { template: '<div />' } },
      { path: '/mon-espace', component: { template: '<div />' } },
    ],
  })
  await router.push('/')
  await router.isReady()
})

afterEach(() => {
  vi.unstubAllGlobals()
  vi.restoreAllMocks()
})

describe('HomeView', () => {
  it('rend l\'accroche "Tu veux partager un fichier ?"', () => {
    const wrapper = mountView()

    expect(wrapper.find('h1').text()).toBe('Tu veux partager un fichier ?')
  })

  it("affiche le bouton d'upload sans carte ouverte", () => {
    const wrapper = mountView()

    expect(wrapper.find('.home-upload-button').exists()).toBe(true)
    expect(wrapper.find('.upload-card').exists()).toBe(false)
  })

  it('le header contient un lien vers /login', () => {
    const wrapper = mountView()

    const link = wrapper.find('.app-header-login')
    expect(link.exists()).toBe(true)
    expect(link.attributes('href')).toBe('/login')
  })

  it('redirige un visiteur non authentifié vers la connexion, retour prévu sur la page courante', async () => {
    const pushSpy = vi.spyOn(router, 'push')
    const wrapper = mountView()

    await wrapper.find('.home-upload-button').trigger('click')
    await flushPromises()

    expect(pushSpy).toHaveBeenCalledWith({ path: '/login', query: { redirect: '/' } })
    expect(wrapper.find('.upload-card').exists()).toBe(false)
  })

  it('ouvre la carte de téléversement pour un utilisateur authentifié', async () => {
    localStorage.setItem(TOKEN_STORAGE_KEY, 'jwt-test')
    const pushSpy = vi.spyOn(router, 'push')
    const wrapper = mountView()

    await wrapper.find('.home-upload-button').trigger('click')
    await flushPromises()

    expect(pushSpy).not.toHaveBeenCalled()
    expect(wrapper.find('.upload-card').exists()).toBe(true)
    expect(wrapper.find('.home-upload-button').exists()).toBe(false)
  })
})
