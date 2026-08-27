/**
 * Un 413 peut être produit avant que l'application n'ait la main (limite du
 * serveur web), auquel cas le corps n'est pas du JSON : la lecture ne doit pas
 * transformer une erreur attendue en échec inattendu.
 */
export async function readJson<T>(response: Response): Promise<T | null> {
  try {
    return (await response.json()) as T
  } catch {
    return null
  }
}
