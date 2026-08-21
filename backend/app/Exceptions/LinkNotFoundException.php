<?php

namespace App\Exceptions;

/**
 * Aucune ligne ne porte ce token. Volontairement indistinguable d'un lien
 * jamais émis (docs/architecture.md) : le corps ne dit ni si le token est mal
 * formé, ni s'il a été révoqué, ni si la purge (US10) a effacé sa ligne.
 */
class LinkNotFoundException extends LinkException
{
    public function __construct()
    {
        parent::__construct(404, 'Ce lien de téléchargement est invalide.');
    }
}
