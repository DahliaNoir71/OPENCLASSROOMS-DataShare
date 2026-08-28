<?php

namespace App\Http\Controllers\Links;

use App\Exceptions\InvalidLinkPasswordException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Links\DownloadLinkRequest;
use App\Http\Resources\LinkMetadataResource;
use App\Services\DownloadLinkService;
use App\Services\FileStorageService;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\StreamedResponse;

class LinkController extends Controller
{
    /**
     * Métadonnées visibles avant téléchargement, pour un destinataire anonyme.
     *
     * La présence des octets sur le disque n'est pas contrôlée ici : ce serait
     * une entrée-sortie de plus par affichage — une requête de métadonnées sur
     * un driver distant — pour un état qui est une faute serveur, et que le
     * téléchargement revérifie de toute façon. C'est le principe déjà retenu
     * pour l'expiration (docs/architecture.md) : la vérification qui compte est
     * toujours la dernière, celle qui précède immédiatement l'ouverture du flux.
     */
    public function show(string $token, DownloadLinkService $links): LinkMetadataResource
    {
        return LinkMetadataResource::make($links->resolve($token));
    }

    /**
     * POST et non GET : la requête porte un secret, qui n'a rien à faire dans
     * une URL — celle-ci part dans les journaux d'accès, l'historique du
     * navigateur et le Referer. La méthode est la même que le fichier soit
     * protégé ou non : un seul chemin de code, un seul jeu d'états d'erreur, et
     * rien qu'un intermédiaire soit autorisé à stocker par défaut.
     *
     * Ordre des contrôles, non négociable : existence, expiration, mot de
     * passe, présence physique, flux. Un lien échu répond donc 410 sans qu'un
     * bcrypt soit calculé.
     */
    public function download(
        DownloadLinkRequest $request,
        string $token,
        DownloadLinkService $links,
        FileStorageService $files,
    ): StreamedResponse {
        $start = hrtime(true);

        $file = $links->resolve($token);

        try {
            $links->assertPasswordMatches($file, $request->validated('password'));
        } catch (InvalidLinkPasswordException $e) {
            // Écrite depuis le contrôleur, comme Login failed : un échec isolé
            // est une réponse normale, sa concentration signale une force
            // brute. Le 429 du limiteur ne dit pas quel fichier était visé,
            // cette ligne le dit — par son identifiant numérique, jamais par
            // son token ni par son nom d'origine.
            Log::warning('Link password failed', ['file_id' => $file->id, 'ip' => $request->ip()]);

            throw $e;
        }

        $response = $files->stream($file);

        // Piste d'audit (docs/architecture.md) : l'appelant est anonyme, il n'y
        // a donc que le fichier à identifier. Écrite après stream(), qui est ce
        // qui peut encore échouer : un lien dont les octets ont disparu n'a pas
        // été consommé.
        // duration_ms (A8) ne couvre que la résolution : la StreamedResponse
        // rend la main avant le transfert des octets, qui n'est donc pas
        // mesuré ici (documenté dans PERF.md).
        Log::info('Link consumed', [
            'file_id' => $file->id,
            'duration_ms' => (int) round((hrtime(true) - $start) / 1_000_000),
            'bytes' => $file->size,
            'route' => $request->route()?->uri(),
        ]);

        return $response;
    }
}
