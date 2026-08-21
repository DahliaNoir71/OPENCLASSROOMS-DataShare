<?php

namespace App\Exceptions;

/**
 * Le lien existe mais son échéance est passée. Un 410 plutôt qu'un 404
 * uniforme : l'information « ce fichier a existé » est assumée
 * (docs/architecture.md). Elle ne coûte rien — pour l'obtenir il faut déjà
 * détenir 22 caractères base62, donc tenir le secret d'un partage — et elle
 * évite d'envoyer un destinataire légitime chercher une faute de copie
 * inexistante là où son lien a simplement échu.
 *
 * La fenêtre est bornée par la purge : une fois la ligne supprimée, le même
 * lien répond 404.
 */
class LinkExpiredException extends LinkException
{
    /**
     * Repris par LinkContentMissingException : deux causes serveur distinctes,
     * un seul état pour le destinataire.
     */
    public const MESSAGE = "Ce lien a expiré : le fichier n'est plus disponible.";

    public function __construct()
    {
        parent::__construct(410, self::MESSAGE);
    }
}
