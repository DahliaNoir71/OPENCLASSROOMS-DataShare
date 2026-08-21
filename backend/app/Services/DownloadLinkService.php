<?php

namespace App\Services;

use App\Exceptions\InvalidLinkPasswordException;
use App\Exceptions\LinkExpiredException;
use App\Exceptions\LinkNotFoundException;
use App\Models\File;
use Illuminate\Support\Facades\Hash;

/**
 * Les règles du lien public, séparées du disque : ce que vaut un token, et ce
 * qu'il faut présenter pour en obtenir les octets. Le stockage lui-même reste
 * l'affaire de FileStorageService.
 *
 * `resolve()` est le seul point du code qui traduise un token en fichier.
 * C'est ce qui garantit — par construction, non par relecture — que
 * `GET /links/{token}` et `POST /links/{token}/download` répondent le même
 * code au même état : il n'existe pas deux endroits où en décider.
 */
class DownloadLinkService
{
    /**
     * @throws LinkNotFoundException
     * @throws LinkExpiredException
     */
    public function resolve(string $token): File
    {
        // Une seule requête, sur l'index unique de la colonne : c'est le seul
        // accès à fort volume du service (docs/architecture.md).
        $file = File::where('token', $token)->first();

        if ($file === null) {
            throw new LinkNotFoundException;
        }

        // Revérifiée à chaque appel, jamais figée dans une colonne d'état :
        // entre l'affichage des métadonnées et le clic, le lien a pu échoir.
        if ($file->isExpired()) {
            throw new LinkExpiredException;
        }

        return $file;
    }

    /**
     * Appelée après resolve(), jamais avant : inutile de faire calculer un
     * bcrypt à qui n'obtiendra rien de toute façon.
     *
     * Un mot de passe fourni sur un fichier non protégé est ignoré sans
     * erreur : il n'y a rien à vérifier, et un front qui renverrait une valeur
     * périmée n'a pas à être puni pour ça.
     *
     * @throws InvalidLinkPasswordException
     */
    public function assertPasswordMatches(File $file, ?string $password): void
    {
        if (! $file->isProtected()) {
            return;
        }

        if ($password === null || ! Hash::check($password, $file->password)) {
            throw new InvalidLinkPasswordException;
        }
    }
}
