<?php

namespace Tests\Feature\Files;

use App\Models\File;
use App\Models\User;
use App\Services\FileStorageService;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\UnableToDeleteFile;
use Tests\TestCase;

class PurgeTest extends TestCase
{
    use RefreshDatabase;

    private const COMMAND = 'files:purge-expired';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('uploads');
    }

    /**
     * La factory ne génère qu'un `stored_path` : aucun octet n'existe sur le
     * disque tant qu'on ne l'y écrit pas (même précaution que DeleteFileTest).
     */
    private function withContent(File $file): File
    {
        Storage::disk('uploads')->put($file->stored_path, 'contenu');

        return $file;
    }

    /**
     * Le service est injecté dans `handle()`, donc résolu du conteneur au
     * moment de l'exécution : l'instance posée ici est bien celle que la
     * commande utilisera.
     *
     * Une sous-classe anonyme plutôt qu'un mock : on a besoin des DEUX
     * comportements dans le même passage — l'échec sur une ligne, la vraie
     * suppression sur les autres — et `parent::delete()` l'exprime en une
     * ligne, là où un mock demanderait des attentes appariées par argument sur
     * des modèles Eloquent.
     *
     * `UnableToDeleteFile::atLocation()` est ce que lève réellement l'adaptateur
     * local quand `unlink` échoue, disque configuré en 'throw' => true. Son
     * message porte le chemin physique : c'est ce qui rend PurgeAuditTest
     * capable de prouver que ce chemin ne ressort jamais dans le journal.
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

    public function test_an_expired_file_is_removed_from_the_database(): void
    {
        $file = File::factory()->expired()->create();

        $this->artisan(self::COMMAND)->assertExitCode(Command::SUCCESS);

        $this->assertDatabaseMissing('files', ['id' => $file->id]);
    }

    public function test_an_expired_file_is_removed_from_the_disk(): void
    {
        $file = $this->withContent(File::factory()->expired()->create());

        $this->artisan(self::COMMAND)->assertExitCode(Command::SUCCESS);

        Storage::disk('uploads')->assertMissing($file->stored_path);
    }

    public function test_an_active_file_is_left_untouched(): void
    {
        // L'état actif est le DÉFAUT de la factory : il n'existe pas d'état
        // `active()`, et en inventer un ferait une seconde définition de
        // l'échéance à côté de celle des scopes.
        $file = $this->withContent(File::factory()->create());

        $this->artisan(self::COMMAND)->assertExitCode(Command::SUCCESS);

        $this->assertDatabaseHas('files', ['id' => $file->id]);
        Storage::disk('uploads')->assertExists($file->stored_path);
    }

    /**
     * Le parcours ne saute rien, même à plus d'un lot. La taille de lot est
     * abaissée par configuration plutôt que par un millier de lignes : cinq
     * fichiers et un lot de deux suffisent à distinguer une pagination par clé
     * d'une pagination par décalage.
     *
     * Chiffre exact d'une implémentation fautive, pour mémoire : avec un
     * OFFSET, le premier lot supprime les identifiants 1 et 2 ; le deuxième lit
     * `OFFSET 2` sur une table où il ne reste que 3, 4, 5, et tombe donc sur 5 ;
     * le troisième lit `OFFSET 4` sur [3, 4] et ne trouve rien, ce qui arrête la
     * boucle. Deux fichiers sur cinq survivent, en silence, et le rapport
     * annonce fièrement trois suppressions. C'est ce test-ci, et lui seul, qui
     * le voit.
     */
    public function test_every_expired_file_is_purged_across_several_chunks(): void
    {
        config(['datashare.purge.chunk' => 2]);

        $user = User::factory()->create();
        File::factory()->for($user)->expired()->count(5)->create();
        $active = File::factory()->for($user)->create();

        $this->artisan(self::COMMAND)->assertExitCode(Command::SUCCESS);

        $this->assertSame(1, File::count());
        $this->assertDatabaseHas('files', ['id' => $active->id]);
    }

    /**
     * La frontière exacte. `expired` est un `<` strict et `active` un `>=` :
     * un fichier pile à l'échéance appartient à `active`, donc ne doit PAS
     * être purgé. FileScopesTest prouve déjà cette partition côté requête ;
     * ce test-ci prouve que la commande n'introduit pas un second `now()`,
     * résolu ailleurs, qui décalerait la frontière de quelques microsecondes.
     *
     * `startOfSecond()` : la valeur relue en base n'a pas les microsecondes de
     * l'instance en mémoire (même précaution que FileScopesTest).
     *
     * `->run()` explicite, et c'est indispensable : `artisan()` rend un
     * PendingCommand qui ne s'exécute qu'à sa destruction, et `travelTo`
     * rétablit l'horloge dès la sortie de la closure. Sans `run()`, la
     * commande tournerait à l'heure réelle, après le dégel — le gel du temps
     * ne couvrirait pas l'exécution qu'il est censé couvrir.
     */
    public function test_a_file_expiring_at_the_exact_instant_of_the_run_is_not_purged(): void
    {
        $instant = now()->startOfSecond();

        $file = $this->travelTo(
            $instant,
            fn () => File::factory()->create(['expires_at' => $instant]),
        );

        $this->travelTo($instant, function (): void {
            $this->artisan(self::COMMAND)->assertExitCode(Command::SUCCESS)->run();
        });

        $this->assertDatabaseHas('files', ['id' => $file->id]);
    }

    public function test_a_file_whose_content_is_already_missing_is_still_removed(): void
    {
        // Storage::fake('uploads') en setUp : aucun octet écrit, le contenu
        // est donc déjà absent au moment de la purge.
        $file = File::factory()->expired()->create();

        $this->artisan(self::COMMAND)->assertExitCode(Command::SUCCESS);

        $this->assertDatabaseMissing('files', ['id' => $file->id]);
    }

    public function test_a_second_run_leaves_the_state_unchanged(): void
    {
        $user = User::factory()->create();
        File::factory()->for($user)->expired()->count(2)->create();
        $active = File::factory()->for($user)->create();

        $this->artisan(self::COMMAND)->assertExitCode(Command::SUCCESS);
        $this->artisan(self::COMMAND)->assertExitCode(Command::SUCCESS);

        $this->assertSame(1, File::count());
        $this->assertDatabaseHas('files', ['id' => $active->id]);
    }

    /**
     * US10 n'ajoute aucun contrôle d'accès : l'inaccessibilité est déjà
     * immédiate, la purge ne fait que matérialiser une disparition différée.
     * Seule l'échéance décide, jamais le propriétaire — ce test casserait une
     * implémentation qui aurait recopié l'idiome `$user->files()` du contrôleur.
     */
    public function test_expiry_alone_decides_regardless_of_owner(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();

        $aliceExpired = File::factory()->for($alice)->expired()->create();
        $bobExpired = File::factory()->for($bob)->expired()->create();
        $bobActive = File::factory()->for($bob)->create();

        $this->artisan(self::COMMAND)->assertExitCode(Command::SUCCESS);

        $this->assertDatabaseMissing('files', ['id' => $aliceExpired->id]);
        $this->assertDatabaseMissing('files', ['id' => $bobExpired->id]);
        $this->assertDatabaseHas('files', ['id' => $bobActive->id]);
    }

    /**
     * Un échec isolé n'empêche pas les autres lignes d'être purgées, et la
     * ligne en échec reste en base : c'est précisément cette survivance qui
     * rend le passage suivant capable de réparer (cf. test_a_second_run...).
     */
    public function test_a_failing_deletion_does_not_stop_the_other_files(): void
    {
        $user = User::factory()->create();
        [$first, $failing, $last] = File::factory()->for($user)->expired()->count(3)->create()->all();

        $this->failOn($failing);

        $this->artisan(self::COMMAND)->assertExitCode(Command::FAILURE);

        $this->assertDatabaseMissing('files', ['id' => $first->id]);
        $this->assertDatabaseMissing('files', ['id' => $last->id]);
        $this->assertDatabaseHas('files', ['id' => $failing->id]);
    }

    public function test_a_run_with_failures_returns_a_non_zero_exit_code(): void
    {
        $file = File::factory()->expired()->create();

        $this->failOn($file);

        $this->artisan(self::COMMAND)->assertExitCode(Command::FAILURE);
    }

    public function test_a_run_without_expired_files_succeeds(): void
    {
        $active = File::factory()->create();

        $this->artisan(self::COMMAND)->assertExitCode(Command::SUCCESS);

        $this->assertDatabaseHas('files', ['id' => $active->id]);
    }

    public function test_purging_the_last_expired_file_of_the_day_removes_the_now_empty_directory(): void
    {
        $file = $this->withContent(File::factory()->expired()->create());
        $dayDirectory = dirname($file->stored_path);

        $this->artisan(self::COMMAND)->assertExitCode(Command::SUCCESS);

        $this->assertDirectoryDoesNotExist(Storage::disk('uploads')->path($dayDirectory));
    }

    /**
     * Deux fichiers du même jour (défaut de la factory) : purger l'expiré ne
     * doit pas emporter le répertoire tant que l'actif y a encore son
     * contenu.
     */
    public function test_purging_one_of_two_files_from_the_same_day_leaves_the_directory_when_the_other_survives(): void
    {
        $user = User::factory()->create();
        $expired = $this->withContent(File::factory()->for($user)->expired()->create());
        $active = $this->withContent(File::factory()->for($user)->create());
        $dayDirectory = dirname($expired->stored_path);

        $this->artisan(self::COMMAND)->assertExitCode(Command::SUCCESS);

        $this->assertDirectoryExists(Storage::disk('uploads')->path($dayDirectory));
        Storage::disk('uploads')->assertExists($active->stored_path);
    }
}
