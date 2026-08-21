<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Base des états qu'un lien public peut opposer à un destinataire anonyme.
 * Deux choix d'implémentation, tous deux nécessaires plutôt que décoratifs.
 *
 * Étendre `HttpException` la place dans la liste `internalDontReport` du
 * gestionnaire d'exceptions : un 404 ou un 410 — réponses normales, pas des
 * incidents — ne remplit pas les journaux d'une trace de pile, au même titre
 * qu'un 422 ou qu'un 429.
 *
 * `render()` n'est pas cosmétique : sans elle, le gestionnaire passe par
 * `convertExceptionToArray()`, qui sous `APP_DEBUG=true` ajoute au corps la
 * classe, le fichier, la ligne et la trace complète. Or `APP_DEBUG` vaut
 * `true` en développement comme dans la suite de tests. Le contrat d'API ne
 * doit dépendre ni de l'un ni de l'autre. C'est la même raison qui a fait
 * écrire les closures `$exceptions->render()` de `bootstrap/app.php` ;
 * appliquée ici au plus près de l'état décrit, elle n'a pas besoin d'y grossir.
 */
abstract class LinkException extends HttpException
{
    public function render(Request $request): JsonResponse
    {
        return response()->json(['message' => $this->getMessage()], $this->getStatusCode());
    }
}
