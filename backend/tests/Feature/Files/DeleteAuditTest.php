<?php

namespace Tests\Feature\Files;

use App\Models\File;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CapturesLogs;
use Tests\TestCase;

class DeleteAuditTest extends TestCase
{
    use CapturesLogs;
    use RefreshDatabase;

    private const PASSWORD = 'password123';

    private const DELETED = 'File deleted';

    private const REFUSED = 'File deletion refused';

    private const CONTENT_MISSING = 'File content already missing';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('uploads');
        $this->captureLogs();
    }

    private function user(): User
    {
        return User::factory()->create(['password' => self::PASSWORD]);
    }

    private function login(User $user): string
    {
        $response = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => self::PASSWORD,
        ]);

        $response->assertOk();

        $this->app['auth']->forgetGuards();
        $this->app['tymon.jwt']->unsetToken();
        $this->app['tymon.jwt.auth']->unsetToken();

        return $response->json('token');
    }

    private function url(int|string $id): string
    {
        return "/api/files/{$id}";
    }

    public function test_a_successful_deletion_leaves_an_audit_line(): void
    {
        $user = $this->user();
        $token = $this->login($user);
        $file = File::factory()->for($user)->create();

        $this->withToken($token)->deleteJson($this->url($file->id))->assertNoContent();

        $lines = $this->logsWithMessage(self::DELETED);

        $this->assertCount(1, $lines);
        $this->assertSame('info', $lines[0]->level);
        $this->assertSame([
            'user_id' => $user->id,
            'file_id' => $file->id,
        ], $lines[0]->context);
    }

    /**
     * Résolution en deux temps (arbitrage B1) : la ligne existe, mais
     * appartient à un autre compte — c'est ce cas précis, et lui seul, qui
     * écrit un refus. Le corps de la réponse reste indistinguable d'un
     * identifiant inexistant (cf. DeleteFileTest).
     */
    public function test_deleting_another_users_file_leaves_a_refusal_line(): void
    {
        $owner = $this->user();
        $other = $this->user();
        $token = $this->login($other);
        $file = File::factory()->for($owner)->create();

        $this->withToken($token)->deleteJson($this->url($file->id))->assertNotFound();

        $lines = $this->logsWithMessage(self::REFUSED);

        $this->assertCount(1, $lines);
        $this->assertSame('warning', $lines[0]->level);
        $this->assertSame([
            'user_id' => $other->id,
            'file_id' => $file->id,
        ], $lines[0]->context);
    }

    public function test_an_unknown_id_leaves_no_audit_line(): void
    {
        $token = $this->login($this->user());

        $this->withToken($token)->deleteJson($this->url(999_999))->assertNotFound();

        $this->assertSame([], $this->logsWithMessage(self::REFUSED));
        $this->assertSame([], $this->logsWithMessage(self::DELETED));
    }

    public function test_a_missing_disk_content_leaves_a_warning_line(): void
    {
        $user = $this->user();
        $token = $this->login($user);
        // Storage::fake('uploads') en setUp : aucun octet écrit, le contenu
        // est donc déjà absent au moment de la suppression.
        $file = File::factory()->for($user)->create();

        $this->withToken($token)->deleteJson($this->url($file->id))->assertNoContent();

        $lines = $this->logsWithMessage(self::CONTENT_MISSING);

        $this->assertCount(1, $lines);
        $this->assertSame('warning', $lines[0]->level);
        $this->assertSame(['file_id' => $file->id], $lines[0]->context);
    }

    /**
     * Le même principe que pour le dépôt (cf. UploadAuditTest) : aucune
     * donnée personnelle ni aucun secret dans la piste d'audit.
     */
    public function test_the_logged_context_carries_no_personal_data(): void
    {
        $owner = $this->user();
        $other = $this->user();
        $token = $this->login($other);
        $file = File::factory()->for($owner)->protected('secret123')->create([
            'original_name' => 'mon-fichier-confidentiel.pdf',
        ]);

        $this->withToken($token)->deleteJson($this->url($file->id))->assertNotFound();

        $context = $this->logsWithMessage(self::REFUSED)[0]->context;

        $this->assertSame(['user_id', 'file_id'], array_keys($context));

        $encoded = json_encode($context);
        $this->assertStringNotContainsString('mon-fichier-confidentiel', $encoded);
        $this->assertStringNotContainsString($file->token, $encoded);
        $this->assertStringNotContainsString($owner->email, $encoded);
        $this->assertStringNotContainsString($other->email, $encoded);
    }
}
