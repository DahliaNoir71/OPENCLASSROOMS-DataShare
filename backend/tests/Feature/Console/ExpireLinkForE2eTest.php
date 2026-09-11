<?php

namespace Tests\Feature\Console;

use App\Models\File;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * e2e:expire-link n'est pas une fonctionnalité produit : ce test prouve
 * seulement qu'elle fait ce que le scénario Cypress 410 attend d'elle
 * (faire échoir une ligne existante) et qu'elle refuse de s'exécuter en
 * production, garde-fou indispensable puisqu'elle modifie une ligne sans
 * aucun contrôle applicatif.
 */
class ExpireLinkForE2eTest extends TestCase
{
    use RefreshDatabase;

    private const COMMAND = 'e2e:expire-link';

    public function test_it_expires_an_active_file(): void
    {
        $file = File::factory()->create(['expires_at' => now()->addDays(7)]);

        $this->artisan(self::COMMAND, ['token' => $file->token])
            ->assertExitCode(Command::SUCCESS);

        $this->assertTrue($file->fresh()->isExpired());
    }

    public function test_it_is_idempotent(): void
    {
        $file = File::factory()->expired()->create();
        $expiresAt = $file->expires_at;

        $this->artisan(self::COMMAND, ['token' => $file->token])
            ->assertExitCode(Command::SUCCESS);

        $this->assertTrue($file->fresh()->isExpired());
        $this->assertNotEquals($expiresAt->toDateTimeString(), $file->fresh()->expires_at->toDateTimeString());
    }

    public function test_it_fails_on_an_unknown_token(): void
    {
        $this->artisan(self::COMMAND, ['token' => 'unknown-token'])
            ->assertExitCode(Command::FAILURE);
    }

    public function test_it_refuses_to_run_in_production(): void
    {
        $file = File::factory()->create(['expires_at' => now()->addDays(7)]);

        $this->app->detectEnvironment(fn () => 'production');

        $this->artisan(self::COMMAND, ['token' => $file->token])
            ->assertExitCode(Command::FAILURE);

        $this->assertFalse($file->fresh()->isExpired());
    }
}
