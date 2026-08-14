import { describe, it, expect, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter, type Router } from 'vue-router'

import HomeView from '../HomeView.vue'

let router: Router

function mountView() {
  return mount(HomeView, {
    global: { plugins: [router] },
  })
}

beforeEach(() => {
  router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/', component: HomeView },
      { path: '/register', component: { template: '<div />' } },
    ],
  })
})

describe('HomeView', () => {
  it('rend l\'accroche "Tu veux partager un fichier ?"', () => {
    const wrapper = mountView()

    expect(wrapper.find('h1').text()).toBe('Tu veux partager un fichier ?')
  })

  it('affiche le bouton d\'upload et le désactive', () => {
    const wrapper = mountView()

    const button = wrapper.find('.home-upload-button')
    expect(button.exists()).toBe(true)
    expect(button.attributes('disabled')).toBeDefined()
    expect(button.attributes('aria-disabled')).toBe('true')
  })

  it('le header contient un lien vers /register', () => {
    const wrapper = mountView()

    const link = wrapper.find('.app-header-login')
    expect(link.exists()).toBe(true)
    expect(link.attributes('href')).toBe('/register')
  })
})
