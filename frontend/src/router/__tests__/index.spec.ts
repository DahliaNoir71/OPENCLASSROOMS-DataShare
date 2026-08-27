import { describe, expect, it } from 'vitest'

import router from '../index'

describe('router', () => {
  it('résout un chemin totalement étranger vers l\'écran 404', async () => {
    await router.push('/une-route-qui-n-existe-pas')
    await router.isReady()

    expect(router.currentRoute.value.name).toBe('not-found')
  })

  it("continue de résoudre /l/:token vers l'écran de téléchargement (non-régression)", async () => {
    await router.push('/l/un-token-quelconque')
    await router.isReady()

    expect(router.currentRoute.value.name).toBe('download')
  })
})
