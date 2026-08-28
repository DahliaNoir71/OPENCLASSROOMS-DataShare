import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'

import AppFooter from '../AppFooter.vue'

describe('AppFooter', () => {
  it('affiche le copyright avec l\'année courante', () => {
    const wrapper = mount(AppFooter)

    expect(wrapper.text()).toBe(`Copyright DataShare® ${new Date().getFullYear()}`)
  })
})
