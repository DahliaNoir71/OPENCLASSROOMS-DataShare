<?php

namespace App\Console\Commands;

use App\Models\File;
use Illuminate\Console\Command;

/**
 * Outillage de test uniquement, hors du chemin applicatif : force
 * l'expiration d'un lien pour que le scénario e2e Cypress (410) puisse
 * l'exercer sans attendre une vraie échéance, et sert aussi à préparer une
 * démonstration. Gardée par l'environnement car elle modifie une ligne de
 * production sans aucun contrôle applicatif (mot de passe, propriétaire) :
 * un token e2e ne doit jamais pouvoir être rejoué contre la production.
 */
class ExpireLinkForE2e extends Command
{
    protected $signature = 'e2e:expire-link
        {token : Token du lien à faire expirer}';

    protected $description = 'Force l\'expiration d\'un lien de partage (tests e2e Cypress et démonstrations uniquement)';

    public function handle(): int
    {
        if (app()->environment('production')) {
            $this->error('Cette commande est réservée aux environnements de test et de démonstration, pas à la production.');

            return self::FAILURE;
        }

        $file = File::where('token', $this->argument('token'))->first();

        if ($file === null) {
            $this->error('Aucun fichier ne correspond à ce token.');

            return self::FAILURE;
        }

        $file->expires_at = now()->subMinute();
        $file->save();

        $this->info("Lien {$file->token} expiré.");

        return self::SUCCESS;
    }
}
