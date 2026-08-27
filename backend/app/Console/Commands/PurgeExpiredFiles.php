<?php

namespace App\Console\Commands;

use App\Models\File;
use App\Services\FileStorageService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Le seul traitement du service qui n'ait pas de client (docs/architecture.md) :
 * ni réponse HTTP, ni utilisateur devant l'écran. Sa seule remontée est la ligne
 * `Expired files purged` écrite en fin de passage — et c'est pour cela qu'elle
 * est écrite même quand rien n'a été trouvé : un zéro prouve que le scheduler
 * tourne, alors que le silence ne distingue pas « rien à faire » de « entrée
 * cron absente ».
 *
 * L'ordre des deux suppressions — octets d'abord, ligne ensuite — n'est pas
 * ici : il appartient à FileStorageService::delete(), que la suppression
 * manuelle (US06) appelle déjà. La purge n'en est que le second appelant, ce
 * qui garantit qu'il n'existe qu'une seule façon de faire disparaître un
 * fichier dans tout le service — et que l'inversion de cet ordre, qui laisserait
 * des octets orphelins qu'aucun passage ne retrouverait, ne peut pas survenir
 * d'un seul côté.
 */
class PurgeExpiredFiles extends Command
{
    protected $signature = 'files:purge-expired';

    protected $description = 'Delete expired files, stored content first, then rows';

    /**
     * `lazyById` — pagination par CLÉ, jamais par décalage. `chunk()` et son
     * dérivé `each()` calculent un OFFSET côté SQL : sur une table qui rétrécit
     * sous le curseur, parce qu'on supprime justement ce qu'on traverse, un
     * OFFSET saute des lignes en silence (six lignes purgées deux par deux :
     * 1-2, puis 5-6, puis rien — 3 et 4 survivent sans le moindre signal).
     * `lazyById` retient le dernier identifiant vu côté PHP et repart de
     * `id > lastId`, ce qu'aucune suppression ne peut décaler.
     *
     * Effet de bord recherché : une ligne en échec, qui reste en base, se
     * retrouve DERRIÈRE le curseur et n'est pas relue — là où une boucle
     * « reprendre les N premières lignes expirées » tournerait indéfiniment
     * dessus. Elle repartira demain, ce qui est exactement la réparation
     * attendue.
     *
     * `File::expired()` résout `now()` une seule fois, à la construction de la
     * requête : la valeur part en binding et `orderedLazyById` clone le builder
     * page après page sans la réévaluer. Le passage a donc une sémantique nette
     * — « tout ce qui avait expiré à l'instant T » — plutôt qu'un ensemble qui
     * grossirait en cours de route, dont le décompte final ne serait
     * reproductible pour personne. Les fichiers qui expirent pendant le passage
     * attendent le suivant : l'architecture assume déjà jusqu'au premier
     * passage qui suit l'échéance.
     *
     * Le compteur d'échecs n'est pas un compteur d'anomalies : une ligne dont
     * les octets manquaient déjà est comptée comme supprimée, parce que le but
     * du passage — plus de ligne, plus d'octets — est atteint. `failed` répond
     * à une seule question, celle de l'exploitant : « reste-t-il du travail que
     * ce passage n'a pas su faire ? »
     */
    public function handle(FileStorageService $files): int
    {
        $deleted = 0;
        $failed = 0;

        foreach (File::expired()->lazyById($this->chunkSize()) as $file) {
            try {
                $files->delete($file);
                $deleted++;

                // Niveau debug, filtré par le LOG_LEVEL=info recommandé en
                // production : gratuit au quotidien, disponible en diagnostic.
                // La disparition d'un fichier expiré ne laisse sinon aucune
                // trace nominative, là où US06 en laisse une (`File deleted`) —
                // cette ligne comble l'écart sans entrer dans la piste d'audit
                // elle-même, qui reste la synthèse ci-dessous.
                Log::debug('Expired file purged', ['file_id' => $file->id]);
            } catch (Throwable $e) {
                $failed++;

                // On continue : un disque qui refuse un fichier n'est pas une
                // raison d'en garder neuf cent quatre-vingt-dix-neuf autres.
                // Rien n'est perdu — la suppression ayant échoué, la ligne est
                // toujours là, et le passage suivant la retrouvera.
                //
                // `Throwable` et non `Exception` : la surface d'échec est
                // hétérogène (UnableToDeleteFile du disque en 'throw' => true,
                // QueryException de la base, TypeError d'une donnée
                // inattendue) et aucune de ces familles ne mérite d'emporter le
                // passage entier.
                //
                // `warning` et non `error` : le critère du projet est la
                // réparation. `Link content missing` est en `error` parce qu'une
                // ligne vivante sans octets ne se répare pas seule ; un échec de
                // purge, lui, se répare au passage suivant, par construction.
                //
                // On journalise la CLASSE de l'exception, jamais son message :
                // celui de Flysystem contient le chemin physique absolu, que la
                // convention exclut au même titre qu'un token (cf.
                // FileStorageService::cleanupOrphan). Ce n'est pas une perte —
                // l'échec est précisément le cas où la ligne survit, donc le
                // chemin reste interrogeable en base à partir de `file_id`. Et
                // la classe répond à la seule question qui compte tout de
                // suite : disque, ou base ?
                Log::warning('Expired file purge failed', [
                    'file_id' => $file->id,
                    'reason' => $e::class,
                ]);
            }
        }

        // Écrite dans tous les cas, y compris à zéro : c'est la preuve de vie du
        // scheduler (docs/architecture.md, « le scheduler n'a pas de client »).
        Log::info('Expired files purged', [
            'deleted' => $deleted,
            'failed' => $failed,
        ]);

        $this->summarize($deleted, $failed);

        // Un code non nul est repris par ScheduleRunCommand, qui en fait une
        // exception reportée et un ScheduledTaskFailed : c'est le seul canal qui
        // alerte sans qu'on lise le journal. Contrepartie assumée : une ligne
        // durablement bloquée fera « échouer » la purge chaque nuit — la bonne
        // réponse est alors de la réparer, pas de taire le signal.
        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Lue en configuration plutôt qu'en littéral, pour la raison déjà écrite
     * pour `history.max_per_page` : c'est ce qui permet à un test de prouver le
     * parcours multi-lots avec cinq fichiers au lieu d'en créer mille un. La
     * valeur elle-même est peu sensible — le coût du passage est dominé par les
     * deux opérations disque de chaque fichier, pas par le nombre de SELECT.
     */
    private function chunkSize(): int
    {
        return (int) config('datashare.purge.chunk');
    }

    /**
     * La commande tourne sous cron, sans terminal, et le scheduler jette sa
     * sortie faute de sendOutputTo(). Cette ligne n'est donc pas la piste
     * d'audit — celle-ci vient d'être écrite au-dessus — mais le retour de la
     * seule autre façon de lancer la purge : à la main, pour un rattrapage.
     * Elle reste volontairement hors contrat, et aucun test ne l'asserte : la
     * figer ferait un second contrat concurrent de la ligne de journal.
     */
    private function summarize(int $deleted, int $failed): void
    {
        $summary = "Expired files purged: {$deleted} deleted, {$failed} failed.";

        if ($failed === 0) {
            $this->info($summary);

            return;
        }

        $this->warn($summary);
    }
}
