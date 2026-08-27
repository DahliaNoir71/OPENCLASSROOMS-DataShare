<?php

namespace Tests\Feature\Console;

use App\Console\Commands\PurgeExpiredFiles;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

/**
 * Ce que ce test prouve, et rien d'autre : la commande de purge est inscrite
 * au scheduler, une seule fois, et à la fréquence attendue. Le comportement
 * de la purge elle-même est couvert ailleurs (PurgeTest, PurgeAuditTest).
 *
 * Il tient sans préparation parce que la planification est déclarée dans
 * routes/console.php : ce fichier est requis par le noyau console pendant
 * Kernel::bootstrap(), que createApplication() appelle avant chaque test.
 * Déclarée dans bootstrap/app.php via withSchedule(), elle ne serait
 * enregistrée qu'au démarrage de l'application Artisan, et events() serait
 * vide ici tant qu'aucune commande n'aurait été appelée.
 */
class PurgeScheduleTest extends TestCase
{
    /**
     * Le nom vient de la commande elle-même, jamais d'un littéral : ce test
     * doit prouver que CETTE classe est planifiée, quel que soit son nom.
     */
    private function commandName(): string
    {
        return (string) app(PurgeExpiredFiles::class)->getName();
    }

    /**
     * @return list<Event>
     */
    private function purgeEvents(): array
    {
        $name = $this->commandName();

        return array_values(array_filter(
            app(Schedule::class)->events(),
            fn (Event $event): bool => str_contains($event->command ?? '', $name),
        ));
    }

    public function test_the_purge_command_is_registered_once_on_the_schedule(): void
    {
        $this->assertCount(1, $this->purgeEvents());
    }

    public function test_the_purge_is_scheduled_daily_at_three(): void
    {
        $event = $this->purgeEvents()[0];

        // $event->command porte le binaire PHP et le chemin d'artisan, tous
        // deux échappés pour le shell — « '/usr/bin/php8.3' 'artisan'
        // files:purge-expired ». Seul le nom de la commande est stable d'une
        // machine à l'autre, d'où assertStringContainsString.
        $this->assertStringContainsString($this->commandName(), $event->command);

        // normalizeCommand() remplace le binaire par « php » et déguillemette
        // le chemin d'artisan : la forme exacte, et portable.
        $this->assertSame(
            'php artisan '.$this->commandName(),
            Event::normalizeCommand($event->command),
        );

        // 0 3 * * * — minute 0, heure 3, tous les jours.
        $this->assertSame('0 3 * * *', $event->expression);
    }

    /**
     * L'expression cron est évaluée dans le fuseau du scheduler, pas dans
     * celui du serveur. Sans cette assertion, un changement d'app.timezone
     * déplacerait le passage sans qu'aucun test ne bronche.
     */
    public function test_the_schedule_is_evaluated_in_utc(): void
    {
        $this->assertSame('UTC', $this->purgeEvents()[0]->timezone);
    }

    /**
     * Les garde-fous non posés le sont délibérément (cf. commentaire de
     * routes/console.php et MAINTENANCE.md) : les fixer ici transforme une
     * décision en contrat, et fait échouer le test le jour où quelqu'un les
     * ajoute sans lire le raisonnement — ce qui est précisément le but.
     */
    public function test_the_purge_carries_no_overlap_guard(): void
    {
        $event = $this->purgeEvents()[0];

        $this->assertFalse($event->withoutOverlapping);
        $this->assertFalse($event->onOneServer);
        $this->assertFalse($event->runInBackground);
        $this->assertSame([], $event->environments);
    }
}
