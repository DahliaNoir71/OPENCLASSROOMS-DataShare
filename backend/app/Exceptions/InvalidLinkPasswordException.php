<?php

namespace App\Exceptions;

/**
 * Mot de passe de partage absent ou incorrect. Un seul code et un seul
 * message pour les deux cas, comme AuthController::INVALID_CREDENTIALS.
 *
 * Ici il n'y a pourtant aucun oracle à protéger — `protected: true` est déjà
 * public dans les métadonnées. La raison est plus simple : c'est un unique
 * échec de vérification, et le front connaît `protected` avant d'afficher le
 * champ, donc le cas « absent » ne survient que sur un appel d'API direct.
 * Deux codes distincts n'apprendraient rien à personne.
 */
class InvalidLinkPasswordException extends LinkException
{
    public function __construct()
    {
        parent::__construct(401, 'Mot de passe incorrect.');
    }
}
