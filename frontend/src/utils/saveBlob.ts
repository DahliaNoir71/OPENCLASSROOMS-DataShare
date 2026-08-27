/**
 * Remise d'un Blob téléchargé au navigateur, sans passer par une navigation :
 * un <a download> synthétique, jamais inséré dans le DOM, suffit à déclencher
 * l'enregistrement. L'URL d'objet est révoquée aussitôt le clic émis, le
 * navigateur en garde sa propre référence pendant le téléchargement.
 */
export function saveBlob(blob: Blob, filename: string): void {
  const url = URL.createObjectURL(blob)
  const link = document.createElement('a')
  link.href = url
  link.download = filename
  link.click()
  URL.revokeObjectURL(url)
}
