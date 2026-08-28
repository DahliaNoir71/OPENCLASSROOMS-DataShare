<?php

namespace App\Logging;

use Illuminate\Log\Logger;
use Monolog\Formatter\JsonFormatter;

/**
 * Tap Laravel : chaque canal fichier passe par ici pour que les lignes soient
 * du JSON exploitable par un outil d'agrégation, sans changer de canal ni de
 * rotation. JsonFormatter restitue le message tel quel dans le champ
 * `message` — le grep littéral de MAINTENANCE.md sur une ligne d'audit
 * survit donc sans adaptation.
 */
class UseJsonFormatter
{
    public function __invoke(Logger $logger): void
    {
        foreach ($logger->getLogger()->getHandlers() as $handler) {
            $handler->setFormatter(new JsonFormatter);
        }
    }
}
