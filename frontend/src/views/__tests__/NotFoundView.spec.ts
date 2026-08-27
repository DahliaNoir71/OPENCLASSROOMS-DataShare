import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia, type Pinia } from 'pinia'
import { createMemoryHistory, createRouter, type Router } from 'vue-router'

import NotFoundView from '../NotFoundView.vue'

let pinia: Pinia
let router: Router

function mountView() {
  return mount(NotFoundView, {
    global: { plugins: [pinia, router] },
  })
}

beforeEach(() => {
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
})

afterEach(() => {
  document.body.innerHTML = ''
})

describe('NotFoundView', () => {
  it('affiche le bandeau erreur "Cette page n\'existe pas."', () => {
    const wrapper = mountView()

    const callout = wrapper.find('.app-callout--error')
    expect(callout.exists()).toBe(true)
    expect(callout.text()).toContain("Cette page n'existe pas.")
  })

  it("affiche un lien de retour vers l'accueil", () => {
    const wrapper = mountView()

    const link = wrapper.find('.not-found-link')
    expect(link.exists()).toBe(true)
    expect(link.attributes('href')).toBe('/')
  })
})
