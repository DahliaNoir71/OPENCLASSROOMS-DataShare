<?php

namespace Tests\Feature\Files;

use App\Models\File;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CapturesLogs;
use Tests\TestCase;

class UploadAuditTest extends TestCase
{
    use CapturesLogs;
    use RefreshDatabase;

    private const PASSWORD = 'password123';

    private const MESSAGE = 'File uploaded';

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

    public function test_a_successful_upload_leaves_an_audit_line(): void
    {
        $user = $this->user();
        $token = $this->login($user);
        $file = UploadedFile::fake()->create('mon-fichier-confidentiel.pdf', 100);

        $this->withToken($token)->postJson('/api/files', ['file' => $file])->assertCreated();

        $fileModel = File::firstOrFail();
        $lines = $this->logsWithMessage(self::MESSAGE);

        $this->assertCount(1, $lines);
        $this->assertSame('info', $lines[0]->level);
        $context = $lines[0]->context;
        $this->assertSame([
            'user_id' => $user->id,
            'file_id' => $fileModel->id,
            'size' => $fileModel->size,
            'protected' => false,
        ], array_diff_key($context, array_flip(['duration_ms', 'route'])));
        $this->assertIsInt($context['duration_ms']);
        $this->assertGreaterThanOrEqual(0, $context['duration_ms']);
        $this->assertSame('api/files', $context['route']);
    }

    public function test_a_refused_upload_leaves_no_audit_line(): void
    {
        $token = $this->login($this->user());
        $file = UploadedFile::fake()->create('malware.exe', 10);

        $this->withToken($token)->postJson('/api/files', ['file' => $file])->assertUnprocessable();

        $this->assertSame([], $this->logsWithMessage(self::MESSAGE));
    }

    public function test_an_unauthenticated_upload_leaves_no_audit_line(): void
    {
        $file = UploadedFile::fake()->create('document.pdf', 10);

        $this->postJson('/api/files', ['file' => $file])->assertUnauthorized();

        $this->assertSame([], $this->logsWithMessage(self::MESSAGE));
    }

    /**
     * Le même principe que pour l'authentification (cf. AuditTrailTest) :
     * aucune donnée personnelle ni aucun secret dans la piste d'audit — ni le
     * nom d'origine, ni le token, ni le mot de passe.
     */
    public function test_the_logged_context_carries_no_personal_data(): void
    {
        $token = $this->login($this->user());
        $file = UploadedFile::fake()->create('mon-fichier-confidentiel.pdf', 100);

        $this->withToken($token)->postJson('/api/files', [
            'file' => $file,
            'password' => 'secret123',
        ])->assertCreated();

        $fileModel = File::firstOrFail();
        $context = $this->logsWithMessage(self::MESSAGE)[0]->context;

        $this->assertSame(
            ['user_id', 'file_id', 'size', 'protected', 'duration_ms', 'route'],
            array_keys($context),
        );

        $encoded = json_encode($context);
        $this->assertStringNotContainsString('mon-fichier-confidentiel', $encoded);
        $this->assertStringNotContainsString($fileModel->token, $encoded);
        $this->assertStringNotContainsString('secret123', $encoded);
    }
}
