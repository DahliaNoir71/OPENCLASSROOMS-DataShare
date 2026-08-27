<?php

namespace Tests\Feature\Files;

use App\Models\File;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class UploadTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'password123';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('uploads');
    }

    private function user(): User
    {
        return User::factory()->create(['password' => self::PASSWORD]);
    }

    /**
     * Mirrors the guard-forgetting trick documented in SessionTest: the guard
     * and the jwt singleton both survive from one request to the next inside
     * a test, which would otherwise answer from memory instead of from the
     * token actually presented.
     */
    private function login(User $user): string
    {
        $response = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => self::PASSWORD,
        ]);

        $response->assertOk();

        $this->forgetGuards();

        return $response->json('token');
    }

    private function forgetGuards(): void
    {
        $this->app['auth']->forgetGuards();
        $this->app['tymon.jwt']->unsetToken();
        $this->app['tymon.jwt.auth']->unsetToken();
    }

    public function test_valid_upload_returns_201_with_expected_structure(): void
    {
        $token = $this->login($this->user());
        $file = UploadedFile::fake()->create('document.pdf', 500, 'application/pdf');

        $response = $this->withToken($token)->postJson('/api/files', ['file' => $file]);

        $response->assertCreated();
        $response->assertJson(fn ($json) => $json->has('data', fn ($json) => $json->hasAll([
            'id', 'original_name', 'size', 'mime_type', 'protected',
            'expires_at', 'expired', 'link', 'created_at',
        ])->etc(false))->etc(false));

        $response->assertJsonPath('data.original_name', 'document.pdf');
        $response->assertJsonPath('data.protected', false);
        $response->assertJsonPath('data.expired', false);

        $this->assertDatabaseCount('files', 1);

        $fileModel = File::first();
        $this->assertSame($fileModel->id, $response->json('data.id'));
        Storage::disk('uploads')->assertExists($fileModel->stored_path);

        $link = $response->json('data.link');
        $this->assertStringStartsWith(rtrim(config('datashare.frontend_url'), '/').'/l/', $link);
        $this->assertStringEndsWith($fileModel->token, $link);
    }

    public function test_upload_without_a_password_is_accepted(): void
    {
        $token = $this->login($this->user());
        $file = UploadedFile::fake()->create('document.pdf', 100);

        $response = $this->withToken($token)->postJson('/api/files', ['file' => $file]);

        $response->assertCreated();
        $response->assertJsonPath('data.protected', false);

        $this->assertNull(File::first()->password);
    }

    public function test_upload_with_a_password_marks_the_file_protected_and_hashes_it(): void
    {
        $token = $this->login($this->user());
        $file = UploadedFile::fake()->create('document.pdf', 100);

        $response = $this->withToken($token)->postJson('/api/files', [
            'file' => $file,
            'password' => 'secret123',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.protected', true);

        $fileModel = File::first();
        $this->assertNotEquals('secret123', $fileModel->password);
        $this->assertTrue(Hash::check('secret123', $fileModel->password));
    }

    public function test_upload_without_expires_in_days_defaults_to_seven_days(): void
    {
        $token = $this->login($this->user());
        $file = UploadedFile::fake()->create('document.pdf', 100);

        $this->withToken($token)->postJson('/api/files', ['file' => $file])->assertCreated();

        $fileModel = File::first();
        $this->assertEqualsWithDelta(
            now()->addDays(7)->getTimestamp(),
            $fileModel->expires_at->getTimestamp(),
            5,
        );
    }

    public function test_upload_with_expires_in_days_sets_the_expiration_accordingly(): void
    {
        $token = $this->login($this->user());
        $file = UploadedFile::fake()->create('document.pdf', 100);

        $this->withToken($token)->postJson('/api/files', [
            'file' => $file,
            'expires_in_days' => 3,
        ])->assertCreated();

        $fileModel = File::first();
        $this->assertEqualsWithDelta(
            now()->addDays(3)->getTimestamp(),
            $fileModel->expires_at->getTimestamp(),
            5,
        );
    }

    /**
     * `""` traverse ConvertEmptyStringsToNull et arrive en validation comme
     * `null`, que `nullable` laisse passer : le champ doit alors se comporter
     * comme absent, pas comme "expire immédiatement".
     */
    public function test_upload_with_an_empty_expires_in_days_defaults_to_seven_days(): void
    {
        $instant = now()->startOfSecond();
        $token = $this->login($this->user());
        $file = UploadedFile::fake()->create('document.pdf', 100);

        $this->travelTo($instant, function () use ($token, $file): void {
            $this->withToken($token)->postJson('/api/files', [
                'file' => $file,
                'expires_in_days' => '',
            ])->assertCreated();
        });

        $fileModel = File::first();
        $this->assertFalse($fileModel->isExpired());
        $this->assertSame(
            $instant->copy()->addDays(7)->getTimestamp(),
            $fileModel->expires_at->getTimestamp(),
        );
    }

    public function test_upload_with_a_null_expires_in_days_defaults_to_seven_days(): void
    {
        $instant = now()->startOfSecond();
        $token = $this->login($this->user());
        $file = UploadedFile::fake()->create('document.pdf', 100);

        $this->travelTo($instant, function () use ($token, $file): void {
            $this->withToken($token)->postJson('/api/files', [
                'file' => $file,
                'expires_in_days' => null,
            ])->assertCreated();
        });

        $fileModel = File::first();
        $this->assertSame(
            $instant->copy()->addDays(7)->getTimestamp(),
            $fileModel->expires_at->getTimestamp(),
        );
    }

    /**
     * Rien ne contraint default_expiry_days <= max_expiry_days en
     * configuration : un défaut au-dessus du plafond doit être ramené au
     * plafond, pas produire un expires_at hors de la borne validée.
     */
    public function test_upload_defaults_to_the_configured_max_when_default_expiry_exceeds_it(): void
    {
        config([
            'datashare.uploads.default_expiry_days' => 10,
            'datashare.uploads.max_expiry_days' => 3,
        ]);

        $instant = now()->startOfSecond();
        $token = $this->login($this->user());
        $file = UploadedFile::fake()->create('document.pdf', 100);

        $this->travelTo($instant, function () use ($token, $file): void {
            $this->withToken($token)->postJson('/api/files', ['file' => $file])->assertCreated();
        });

        $fileModel = File::first();
        $this->assertSame(
            $instant->copy()->addDays(3)->getTimestamp(),
            $fileModel->expires_at->getTimestamp(),
        );
    }

    public function test_upload_without_authentication_returns_401(): void
    {
        $file = UploadedFile::fake()->create('document.pdf', 100);

        $response = $this->postJson('/api/files', ['file' => $file]);

        $response->assertUnauthorized();
        $response->assertExactJson(['message' => 'Authentification requise.']);
        $this->assertDatabaseCount('files', 0);
    }

    public function test_upload_with_a_revoked_token_returns_401(): void
    {
        $token = $this->login($this->user());
        $this->withToken($token)->postJson('/api/auth/logout')->assertOk();
        $this->forgetGuards();

        $file = UploadedFile::fake()->create('document.pdf', 100);
        $response = $this->withToken($token)->postJson('/api/files', ['file' => $file]);

        $response->assertUnauthorized();
        $this->assertDatabaseCount('files', 0);
    }

    public function test_upload_exceeding_the_configured_max_size_returns_422(): void
    {
        // Une taille abaissée en configuration : vérifier le franchissement
        // de la règle sans écrire 1 Go à chaque exécution de la suite.
        config(['datashare.uploads.max_bytes' => 50 * 1024]);

        $token = $this->login($this->user());
        $file = UploadedFile::fake()->create('big.bin', 100);

        $response = $this->withToken($token)->postJson('/api/files', ['file' => $file]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['file']);
        $this->assertDatabaseCount('files', 0);
        $this->assertEmpty(Storage::disk('uploads')->allFiles());
    }

    public function test_upload_with_a_blocked_extension_returns_422(): void
    {
        $token = $this->login($this->user());
        $file = UploadedFile::fake()->create('malware.exe', 10);

        $response = $this->withToken($token)->postJson('/api/files', ['file' => $file]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['file']);
        $this->assertDatabaseCount('files', 0);
        $this->assertEmpty(Storage::disk('uploads')->allFiles());
    }

    public function test_upload_with_expires_in_days_of_eight_returns_422(): void
    {
        $token = $this->login($this->user());
        $file = UploadedFile::fake()->create('document.pdf', 10);

        $response = $this->withToken($token)->postJson('/api/files', [
            'file' => $file,
            'expires_in_days' => 8,
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['expires_in_days']);
    }

    public function test_upload_with_expires_in_days_of_zero_returns_422(): void
    {
        $token = $this->login($this->user());
        $file = UploadedFile::fake()->create('document.pdf', 10);

        $response = $this->withToken($token)->postJson('/api/files', [
            'file' => $file,
            'expires_in_days' => 0,
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['expires_in_days']);
    }

    public function test_upload_with_a_password_shorter_than_six_characters_returns_422(): void
    {
        $token = $this->login($this->user());
        $file = UploadedFile::fake()->create('document.pdf', 10);

        $response = $this->withToken($token)->postJson('/api/files', [
            'file' => $file,
            'password' => 'abc',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['password']);
    }

    public function test_upload_without_a_file_returns_422(): void
    {
        $token = $this->login($this->user());

        $response = $this->withToken($token)->postJson('/api/files', []);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['file']);
    }

    public function test_two_uploads_receive_distinct_tokens_of_the_configured_length(): void
    {
        $token = $this->login($this->user());

        $this->withToken($token)->postJson('/api/files', [
            'file' => UploadedFile::fake()->create('one.pdf', 10),
        ])->assertCreated();

        $this->withToken($token)->postJson('/api/files', [
            'file' => UploadedFile::fake()->create('two.pdf', 10),
        ])->assertCreated();

        $tokens = File::pluck('token');

        $this->assertCount(2, $tokens->unique());

        foreach ($tokens as $fileToken) {
            $this->assertSame(config('datashare.uploads.token_length'), strlen($fileToken));
            $this->assertMatchesRegularExpression('/^[A-Za-z0-9]+$/', $fileToken);
        }
    }

    public function test_the_uploaded_file_is_owned_by_the_authenticated_user(): void
    {
        $user = $this->user();
        $token = $this->login($user);
        $file = UploadedFile::fake()->create('document.pdf', 10);

        $this->withToken($token)->postJson('/api/files', ['file' => $file])->assertCreated();

        $this->assertSame($user->id, File::first()->user_id);
    }

    public function test_upload_response_is_not_storable(): void
    {
        $token = $this->login($this->user());
        $file = UploadedFile::fake()->create('document.pdf', 10);

        $response = $this->withToken($token)->postJson('/api/files', ['file' => $file]);

        $response->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_uploads_are_throttled_after_ten_requests_from_the_same_user(): void
    {
        $token = $this->login($this->user());

        foreach (range(1, 10) as $i) {
            $this->withToken($token)->postJson('/api/files', [
                'file' => UploadedFile::fake()->create("file{$i}.pdf", 10),
            ])->assertCreated();
        }

        $response = $this->withToken($token)->postJson('/api/files', [
            'file' => UploadedFile::fake()->create('file11.pdf', 10),
        ]);

        $response->assertTooManyRequests();
        $response->assertHeader('Retry-After');
        $this->assertDatabaseCount('files', 10);
    }
}
