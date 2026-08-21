<?php

namespace Tests\Feature\Links;

use App\Models\File;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Concerns\CapturesLogs;
use Tests\TestCase;

class DownloadAuditTest extends TestCase
{
    use CapturesLogs;
    use RefreshDatabase;

    private const BYTES = 'contenu du fichier partage';

    private const PASSWORD = 'secret123';

    private const CONSUMED = 'Link consumed';

    private const FAILED = 'Link password failed';

    private const MISSING = 'Link content missing';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('uploads');
        $this->captureLogs();
    }

    private function withBytes(File $file): File
    {
        Storage::disk('uploads')->put($file->stored_path, self::BYTES);

        return $file;
    }

    private function url(File $file): string
    {
        return "/api/links/{$file->token}/download";
    }

    public function test_a_successful_download_leaves_a_link_consumed_line(): void
    {
        $file = $this->withBytes(File::factory()->create(['size' => strlen(self::BYTES)]));

        $this->postJson($this->url($file))->assertOk();

        $lines = $this->logsWithMessage(self::CONSUMED);

        $this->assertCount(1, $lines);
        $this->assertSame('info', $lines[0]->level);
        // L'appelant est anonyme : il n'y a que le fichier à identifier, et par
        // son identifiant numérique.
        $this->assertSame(['file_id' => $file->id], $lines[0]->context);
    }

    /**
     * Le pendant de Login failed : un échec isolé est une réponse normale, sa
     * concentration signale une force brute. Le 429 du limiteur ne dit pas quel
     * fichier était visé, cette ligne le dit.
     */
    public function test_a_wrong_password_leaves_a_warning_line_with_the_file_id_and_the_ip(): void
    {
        $file = $this->withBytes(File::factory()->protected(self::PASSWORD)->create());

        $this->postJson($this->url($file), ['password' => 'mauvais'])->assertUnauthorized();

        $lines = $this->logsWithMessage(self::FAILED);

        $this->assertCount(1, $lines);
        $this->assertSame('warning', $lines[0]->level);
        $this->assertSame(['file_id', 'ip'], array_keys($lines[0]->context));
        $this->assertSame($file->id, $lines[0]->context['file_id']);
        $this->assertSame([], $this->logsWithMessage(self::CONSUMED));
    }

    /**
     * Un balayage de tokens produirait une ligne par tentative et noierait le
     * journal ; le 429 du limiteur couvre déjà ce signal, avec l'IP.
     */
    public function test_an_unknown_token_leaves_no_audit_line(): void
    {
        $this->postJson('/api/links/'.Str::random(22).'/download')->assertNotFound();

        $this->assertSame([], $this->logsWithMessage(self::CONSUMED));
        $this->assertSame([], $this->logsWithMessage(self::FAILED));
    }

    /**
     * L'absence de la seconde ligne dit aussi que le mot de passe — pourtant
     * correct — n'a jamais été vérifié : l'expiration passe avant.
     */
    public function test_an_expired_link_leaves_no_consumed_line(): void
    {
        $file = $this->withBytes(
            File::factory()->expired()->protected(self::PASSWORD)->create()
        );

        $this->postJson($this->url($file), ['password' => self::PASSWORD])->assertGone();

        $this->assertSame([], $this->logsWithMessage(self::CONSUMED));
        $this->assertSame([], $this->logsWithMessage(self::FAILED));
    }

    public function test_a_file_missing_from_disk_leaves_an_error_line(): void
    {
        $file = File::factory()->create();

        $this->postJson($this->url($file))->assertGone();

        $lines = $this->logsWithMessage(self::MISSING);

        $this->assertCount(1, $lines);
        $this->assertSame('error', $lines[0]->level);
        $this->assertSame(['file_id' => $file->id], $lines[0]->context);
        // Le lien n'a pas été consommé : il n'y avait rien à consommer.
        $this->assertSame([], $this->logsWithMessage(self::CONSUMED));
    }

    /**
     * Même règle que pour l'authentification et le dépôt : ni secret porteur,
     * ni donnée personnelle dans la piste d'audit.
     */
    public function test_the_logged_context_carries_no_token_no_filename_and_no_password(): void
    {
        $file = $this->withBytes(File::factory()->protected(self::PASSWORD)->create([
            'original_name' => 'mon-fichier-confidentiel.pdf',
        ]));

        $this->postJson($this->url($file), ['password' => 'mauvais'])->assertUnauthorized();
        $this->postJson($this->url($file), ['password' => self::PASSWORD])->assertOk();

        $encoded = json_encode([
            $this->logsWithMessage(self::FAILED)[0]->context,
            $this->logsWithMessage(self::CONSUMED)[0]->context,
        ]);

        $this->assertStringNotContainsString($file->token, $encoded);
        $this->assertStringNotContainsString($file->stored_path, $encoded);
        $this->assertStringNotContainsString('mon-fichier-confidentiel', $encoded);
        $this->assertStringNotContainsString(self::PASSWORD, $encoded);
    }
}
