<?php

namespace App\Exceptions;

/**
 * La ligne est vivante, ses octets ont disparu du disque. Deux causes
 * plausibles : une purge (US10) interrompue entre la suppression physique et
 * celle de la ligne, ou une intervention manuelle sur le stockage.
 *
 * Même code et même corps que l'expiration : du point de vue du destinataire
 * l'information est exacte, c'est l'état que la purge produira de toute façon,
 * et un 500 n'apprendrait rien d'utile à un appelant anonyme. L'anomalie
 * remonte par l'autre canal, un `Log::error` écrit là où elle est détectée
 * (FileStorageService::stream).
 */
class LinkContentMissingException extends LinkException
{
    public function __construct()
    {
        parent::__construct(410, LinkExpiredException::MESSAGE);
    }
}
