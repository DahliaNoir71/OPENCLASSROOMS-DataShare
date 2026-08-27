import { describe, expect, it } from 'vitest'

import { formatMimeType } from '../formatMimeType'

describe('formatMimeType', () => {
  it('reconnaît les types documentaires courants', () => {
    expect(formatMimeType('application/pdf')).toBe('PDF')
    expect(
      formatMimeType('application/vnd.openxmlformats-officedocument.wordprocessingml.document'),
    ).toBe('Word')
    expect(
      formatMimeType('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'),
    ).toBe('Excel')
  })

  it('déduit le sous-type en majuscules pour une image, un audio ou une vidéo', () => {
    expect(formatMimeType('image/png')).toBe('PNG')
    expect(formatMimeType('audio/mpeg')).toBe('MPEG')
    expect(formatMimeType('video/mp4')).toBe('MP4')
  })

  it('traduit text/plain en « Texte », et retombe sur le sous-type sinon', () => {
    expect(formatMimeType('text/plain')).toBe('Texte')
    expect(formatMimeType('text/csv')).toBe('CSV')
  })

  it('retombe sur le sous-type en majuscules pour un type inconnu', () => {
    expect(formatMimeType('application/x-custom-thing')).toBe('X-CUSTOM-THING')
  })

  it('retourne la valeur brute si elle ne contient pas de sous-type', () => {
    expect(formatMimeType('bogus')).toBe('bogus')
  })
})
