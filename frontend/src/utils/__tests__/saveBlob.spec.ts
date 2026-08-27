import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { saveBlob } from '../saveBlob'

describe('saveBlob', () => {
  const createObjectURLMock = vi.fn<() => string>(() => 'blob:mock-url')
  const revokeObjectURLMock = vi.fn<(url: string) => void>()

  beforeEach(() => {
    createObjectURLMock.mockClear()
    revokeObjectURLMock.mockClear()

    // jsdom n'implémente pas ces deux méthodes statiques : la vraie classe
    // URL sert de base, seuls les deux statiques manquants sont ajoutés.
    vi.stubGlobal(
      'URL',
      class extends URL {
        static override createObjectURL = createObjectURLMock
        static override revokeObjectURL = revokeObjectURLMock
      },
    )
  })

  afterEach(() => {
    vi.unstubAllGlobals()
    vi.restoreAllMocks()
  })

  it('crée une URL d’objet pour le Blob puis la révoque après le clic', () => {
    const clickSpy = vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => {})
    const blob = new Blob(['contenu'])

    saveBlob(blob, 'rapport.pdf')

    expect(createObjectURLMock).toHaveBeenCalledWith(blob)
    expect(clickSpy).toHaveBeenCalledTimes(1)
    expect(revokeObjectURLMock).toHaveBeenCalledWith('blob:mock-url')
  })

  it('pose href et download sur le lien synthétique avant de déclencher le clic', () => {
    let capturedHref = ''
    let capturedDownload = ''
    vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(function (
      this: HTMLAnchorElement,
    ) {
      capturedHref = this.href
      capturedDownload = this.download
    })

    saveBlob(new Blob(['contenu']), 'rapport final.pdf')

    expect(capturedHref).toBe('blob:mock-url')
    expect(capturedDownload).toBe('rapport final.pdf')
  })
})
