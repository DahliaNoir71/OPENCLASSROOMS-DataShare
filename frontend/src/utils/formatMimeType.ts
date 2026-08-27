const KNOWN_TYPES: Record<string, string> = {
  'application/pdf': 'PDF',
  'application/zip': 'ZIP',
  'application/x-zip-compressed': 'ZIP',
  'application/msword': 'Word',
  'application/vnd.openxmlformats-officedocument.wordprocessingml.document': 'Word',
  'application/vnd.ms-excel': 'Excel',
  'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet': 'Excel',
  'application/vnd.ms-powerpoint': 'PowerPoint',
  'application/vnd.openxmlformats-officedocument.presentationml.presentation': 'PowerPoint',
  'application/octet-stream': 'Fichier',
}

/**
 * Libellé lisible du type MIME (US02 : le type doit être visible). Les
 * familles courantes — image, audio, vidéo — se déduisent du premier segment ;
 * le reste retombe sur le sous-type en majuscules plutôt que sur le type brut,
 * illisible pour un destinataire non technique.
 */
export function formatMimeType(mimeType: string): string {
  if (KNOWN_TYPES[mimeType]) {
    return KNOWN_TYPES[mimeType]
  }

  const [type, subtype] = mimeType.split('/')
  if (!subtype) {
    return mimeType
  }

  if (type === 'image' || type === 'audio' || type === 'video') {
    return subtype.toUpperCase()
  }

  if (type === 'text') {
    return subtype === 'plain' ? 'Texte' : subtype.toUpperCase()
  }

  return subtype.toUpperCase()
}
