<?php

use App\Console\Commands\PurgeExpiredFiles;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// La forme ::class plutôt qu'une chaîne de signature : un renommage de la
// commande casse bruyamment ici, au bootstrap, plutôt qu'en silence à 3 h du
// matin quand cron exécute schedule:run sur un nom devenu inconnu.
//
// Ni ->withoutOverlapping() (verrou de 24 h par défaut, qu'un SIGKILL ou un
// OOM laisse en place tout ce temps, pour un recouvrement impossible sur une
// tâche quotidienne de quelques secondes), ni ->onOneServer() (protège d'une
// course impossible sur un hôte unique), ni ->runInBackground() (deux
// processus pour une seule tâche par minute), ni ->environments() (un
// développeur qui lance schedule:work veut aussi voir ses dépôts locaux
// expirés disparaître), ni ->timezone() : l'application n'a qu'une horloge,
// UTC (config('app.timezone')), la même que celle d'expires_at et des
// journaux — en poser une seconde ici exposerait le passage aux deux
// discontinuités du changement d'heure, dont l'une supprime un passage sans
// laisser de trace. `schedule:list --timezone=Europe/Paris` rend la
// lisibilité en heure locale à la demande, sans toucher au code.
//
// L'heure elle-même n'a aucune conséquence métier — la purge est
// idempotente et un passage manqué n'est jamais rattrapé, seulement rejoué
// au suivant — mais 03:00 évite minuit, la minute la plus chargée de tout
// hôte partagé (rotations de journaux, sauvegardes).
Schedule::command(PurgeExpiredFiles::class)->dailyAt('03:00');
