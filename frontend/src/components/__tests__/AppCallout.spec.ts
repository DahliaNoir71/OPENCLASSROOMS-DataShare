import { afterEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'

import AppCallout from '../AppCallout.vue'

afterEach(() => {
  vi.restoreAllMocks()
  document.body.innerHTML = ''
})

describe('AppCallout', () => {
  it('rend le libellé fourni', () => {
    const wrapper = mount(AppCallout, {
      props: { type: 'info', label: 'Ce fichier expirera dans 3 jours.' },
    })

    expect(wrapper.text()).toBe('Ce fichier expirera dans 3 jours.')
  })

  it.each([
    ['info', 'app-callout--info'],
    ['warning', 'app-callout--warning'],
    ['error', 'app-callout--error'],
  ] as const)('applique la classe du type %s', (type, expectedClass) => {
    const wrapper = mount(AppCallout, { props: { type, label: 'Message' } })

    expect(wrapper.classes()).toContain(expectedClass)
  })

  it('masque son icône aux lecteurs d’écran', () => {
    const wrapper = mount(AppCallout, { props: { type: 'info', label: 'Message' } })

    expect(wrapper.find('svg').attributes('aria-hidden')).toBe('true')
  })

  it('expose role="alert" pour le type erreur uniquement', () => {
    const error = mount(AppCallout, { props: { type: 'error', label: 'Message' } })
    const info = mount(AppCallout, { props: { type: 'info', label: 'Message' } })
    const warning = mount(AppCallout, { props: { type: 'warning', label: 'Message' } })

    expect(error.attributes('role')).toBe('alert')
    expect(info.attributes('role')).toBeUndefined()
    expect(warning.attributes('role')).toBeUndefined()
  })

  it('déplace le focus sur le bandeau quand focusOnMount est activé', () => {
    const focusSpy = vi.spyOn(HTMLElement.prototype, 'focus')

    mount(AppCallout, {
      props: { type: 'error', label: 'Message', focusOnMount: true },
      attachTo: document.body,
    })

    expect(focusSpy).toHaveBeenCalled()
  })

  it('ne déplace pas le focus par défaut', () => {
    const focusSpy = vi.spyOn(HTMLElement.prototype, 'focus')

    mount(AppCallout, {
      props: { type: 'error', label: 'Message' },
      attachTo: document.body,
    })

    expect(focusSpy).not.toHaveBeenCalled()
  })
})
