<?php

namespace Tests\Feature\Files;

use App\Models\File;
use App\Models\User;
use App\Services\FileStorageService;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\UnableToDeleteFile;
use Tests\Concerns\CapturesLogs;
use Tests\TestCase;

class PurgeAuditTest extends TestCase
{
    use CapturesLogs;
    use RefreshDatabase;

    private const COMMAND = 'files:purge-expired';

    private const PURGED = 'Expired files purged';

    private const FILE_PURGED = 'Expired file purged';

    private const FAILED = 'Expired file purge failed';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('uploads');
        $this->captureLogs();
    }

    private function withContent(File $file): File
    {
        Storage::disk('uploads')->put($file->stored_path, 'contenu');

        return $file;
    }

    /**
     * Cf. PurgeTest::failOn() — même technique, dupliquée ici comme le fait
     * déjà le projet entre DeleteFileTest et DeleteAuditTest.
     */
    private function failOn(File $failing): void
    {
        $stub = new class((int) $failing->id) extends FileStorageService
        {
            public function __construct(private readonly int $failingId) {}

            public function delete(File $file): void
            {
                if ((int) $file->id === $this->failingId) {
                    throw UnableToDeleteFile::atLocation($file->stored_path, 'panne simulée');
                }

                parent::delete($file);
            }
        };

        $this->app->instance(FileStorageService::class, $stub);
    }

    public function test_a_run_leaves_a_purge_report(): void
    {
        $user = User::factory()->create();
        File::factory()->for($user)->expired()->count(3)->create();
        File::factory()->for($user)->create();

        $this->artisan(self::COMMAND)->assertExitCode(Command::SUCCESS);

        $lines = $this->logsWithMessage(self::PURGED);

        $this->assertCount(1, $lines);
        $this->assertSame('info', $lines[0]->level);
        $this->assertSame(['deleted' => 3, 'failed' => 0], $lines[0]->context);
    }

    /**
     * La preuve de vie. Un passage qui ne trouve rien écrit quand même sa
     * ligne : sans elle, le silence du journal ne distinguerait plus « rien à
     * purger » de « entrée cron absente » — et c'est le seul canal dont ce
     * traitement dispose (docs/architecture.md, « le scheduler n'a pas de
     * client »).
     */
    public function test_a_run_with_nothing_to_purge_still_leaves_a_report(): void
    {
        File::factory()->create();

        $this->artisan(self::COMMAND)->assertExitCode(Command::SUCCESS);

        $lines = $this->logsWithMessage(self::PURGED);

        $this->assertCount(1, $lines);
        $this->assertSame('info', $lines[0]->level);
        $this->assertSame(['deleted' => 0, 'failed' => 0], $lines[0]->context);
    }

    public function test_a_second_run_reports_nothing_purged(): void
    {
        File::factory()->expired()->create();

        $this->artisan(self::COMMAND)->assertExitCode(Command::SUCCESS);
        $this->artisan(self::COMMAND)->assertExitCode(Command::SUCCESS);

        $lines = $this->logsWithMessage(self::PURGED);

        $this->assertCount(2, $lines);
        $this->assertSame(['deleted' => 1, 'failed' => 0], $lines[0]->context);
        $this->assertSame(['deleted' => 0, 'failed' => 0], $lines[1]->context);
    }

    public function test_a_failed_deletion_is_counted_in_the_report(): void
    {
        $user = User::factory()->create();
        [$first, $failing, $last] = File::factory()->for($user)->expired()->count(3)->create()->all();

        $this->failOn($failing);

        $this->artisan(self::COMMAND)->assertExitCode(Command::FAILURE);

        $lines = $this->logsWithMessage(self::PURGED);

        $this->assertCount(1, $lines);
        $this->assertSame(['deleted' => 2, 'failed' => 1], $lines[0]->context);
    }

    public function test_a_failed_deletion_leaves_its_own_line(): void
    {
        $file = File::factory()->expired()->create();
        $this->failOn($file);

        $this->artisan(self::COMMAND)->assertExitCode(Command::FAILURE);

        $lines = $this->logsWithMessage(self::FAILED);

        $this->assertCount(1, $lines);
        $this->assertSame('warning', $lines[0]->level);
        $this->assertSame(['file_id', 'reason'], array_keys($lines[0]->context));
        $this->assertSame($file->id, $lines[0]->context['file_id']);
        $this->assertSame(UnableToDeleteFile::class, $lines[0]->context['reason']);
    }

    /**
     * Tranche l'arbitrage du compteur : un fichier dont les octets manquaient
     * déjà atteint le but du passage — plus de ligne, plus d'octets — et
     * compte donc comme supprimé, pas comme un échec.
     */
    public function test_a_file_whose_content_is_already_missing_counts_as_purged(): void
    {
        // Storage::fake('uploads') en setUp : aucun octet écrit.
        File::factory()->expired()->create();

        $this->artisan(self::COMMAND)->assertExitCode(Command::SUCCESS);

        $lines = $this->logsWithMessage(self::PURGED);

        $this->assertSame(['deleted' => 1, 'failed' => 0], $lines[0]->context);
    }

    /**
     * Le `warning` du service (FileStorageService::delete()) n'est pas avalé
     * par le `try/catch` de la commande : l'absence d'octets n'est pas une
     * exception, elle ne passe donc jamais par la branche d'échec.
     */
    public function test_the_missing_content_warning_still_surfaces_from_the_command(): void
    {
        File::factory()->expired()->create();

        $this->artisan(self::COMMAND)->assertExitCode(Command::SUCCESS);

        $lines = $this->logsWithMessage('File content already missing');

        $this->assertCount(1, $lines);
        $this->assertSame('warning', $lines[0]->level);
    }

    /**
     * B2 : une ligne debug par fichier effectivement purgé, y compris quand
     * ses octets manquaient déjà — c'est le retour de $files->delete() qui
     * décide, pas la présence des octets.
     */
    public function test_a_purged_file_leaves_a_debug_line(): void
    {
        $file = $this->withContent(File::factory()->expired()->create());

        $this->artisan(self::COMMAND)->assertExitCode(Command::SUCCESS);

        $lines = $this->logsWithMessage(self::FILE_PURGED);

        $this->assertCount(1, $lines);
        $this->assertSame('debug', $lines[0]->level);
        $this->assertSame(['file_id' => $file->id], $lines[0]->context);
    }

    public function test_a_purged_file_with_missing_content_still_leaves_a_debug_line(): void
    {
        $file = File::factory()->expired()->create();

        $this->artisan(self::COMMAND)->assertExitCode(Command::SUCCESS);

        $lines = $this->logsWithMessage(self::FILE_PURGED);

        $this->assertCount(1, $lines);
        $this->assertSame(['file_id' => $file->id], $lines[0]->context);
    }

    /**
     * Le pendant négatif : un fichier dont la suppression échoue ne laisse
     * jamais la ligne debug de succès — seulement la ligne warning d'échec.
     */
    public function test_a_failing_deletion_leaves_no_debug_line(): void
    {
        $file = File::factory()->expired()->create();
        $this->failOn($file);

        $this->artisan(self::COMMAND)->assertExitCode(Command::FAILURE);

        $this->assertSame([], $this->logsWithMessage(self::FILE_PURGED));
    }

    public function test_the_report_carries_no_personal_data(): void
    {
        $user = User::factory()->create();
        $file = File::factory()->for($user)->expired()->protected('secret123')->create([
            'original_name' => 'mon-fichier-confidentiel.pdf',
        ]);

        $this->artisan(self::COMMAND)->assertExitCode(Command::SUCCESS);

        $context = $this->logsWithMessage(self::PURGED)[0]->context;

        $this->assertSame(['deleted', 'failed'], array_keys($context));

        $encoded = json_encode($context);
        $this->assertStringNotContainsString('mon-fichier-confidentiel', $encoded);
        $this->assertStringNotContainsString($file->token, $encoded);
        $this->assertStringNotContainsString($user->email, $encoded);
    }

    /**
     * Le message de l'exception que lève réellement l'adaptateur local contient
     * le chemin physique du fichier. Ce test verrouille le fait qu'on
     * journalise la CLASSE de l'exception et jamais son message : un
     * `report($e)` à la manière de Prunable écrirait ce chemin dans le journal,
     * contre la règle que FileStorageService::cleanupOrphan énonce déjà (le
     * chemin est un secret d'accès au même titre qu'un token).
     */
    public function test_a_failure_line_carries_no_stored_path(): void
    {
        $file = File::factory()->expired()->create();
        $this->failOn($file);

        $this->artisan(self::COMMAND)->assertExitCode(Command::FAILURE);

        $lines = $this->logsWithMessage(self::FAILED);

        $this->assertStringNotContainsString(
            $file->stored_path,
            (string) json_encode($lines[0]->context),
        );
    }

    /**
     * La ligne est écrite par la commande, pas par un observateur de modèle.
     * Calque exact de AuditTrailTest::test_creating_a_user_outside_the_api_leaves_no_audit_line.
     */
    public function test_creating_expired_rows_outside_the_command_leaves_no_report(): void
    {
        File::factory()->expired()->count(3)->create();

        $this->assertSame([], $this->logsWithMessage(self::PURGED));
    }
}
