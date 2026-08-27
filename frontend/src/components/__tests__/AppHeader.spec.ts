import { beforeEach, describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia, type Pinia } from 'pinia'
import { createMemoryHistory, createRouter, type Router } from 'vue-router'

import AppHeader from '../AppHeader.vue'
import { TOKEN_STORAGE_KEY } from '@/stores/auth'

let pinia: Pinia
let router: Router

function mountHeader() {
  return mount(AppHeader, {
    global: { plugins: [pinia, router] },
  })
}

beforeEach(async () => {
  localStorage.clear()
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
})
