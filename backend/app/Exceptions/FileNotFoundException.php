<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Aucune ligne accessible par ce compte ne porte cet identifiant.
 * Volontairement indistinguable d'un identifiant inexistant, d'un fichier
 * appartenant à un autre compte, ou d'un identifiant non numérique
 * (docs/architecture.md) : le corps ne dit dans aucun des trois cas lequel
 * s'est produit.
 *
 * Étend `HttpException` directement, et non `LinkException` — cette
 * dernière est le socle des états qu'un lien public oppose à un
 * destinataire anonyme, un domaine distinct de celui-ci. `render()` est
 * dupliqué à l'identique pour la même raison qu'y est documentée : sans
 * elle, `APP_DEBUG=true` (développement et suite de tests) injecterait la
 * trace de pile dans le corps de la réponse.
 */
class FileNotFoundException extends HttpException
{
    public function __construct()
    {
        parent::__construct(404, 'Ce fichier est introuvable.');
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json(['message' => $this->getMessage()], $this->getStatusCode());
    }
}
