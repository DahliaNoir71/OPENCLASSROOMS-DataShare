<?php

namespace Tests\Feature\Files;

use App\Models\File;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DeleteFileTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'password123';

    private const NOT_FOUND = 'Ce fichier est introuvable.';

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

        $this->app['auth']->forgetGuards();
        $this->app['tymon.jwt']->unsetToken();
        $this->app['tymon.jwt.auth']->unsetToken();

        return $response->json('token');
    }

    private function url(int|string $id): string
    {
        return "/api/files/{$id}";
    }

    public function test_a_valid_deletion_returns_204_with_no_content(): void
    {
        $user = $this->user();
        $token = $this->login($user);
        $file = File::factory()->for($user)->create();

        $response = $this->withToken($token)->deleteJson($this->url($file->id));

        $response->assertNoContent();
        $this->assertSame('', $response->getContent());
    }

    public function test_a_valid_deletion_removes_the_database_row(): void
    {
        $user = $this->user();
        $token = $this->login($user);
        $file = File::factory()->for($user)->create();

        $this->withToken($token)->deleteJson($this->url($file->id))->assertNoContent();

        $this->assertDatabaseMissing('files', ['id' => $file->id]);
    }

    public function test_a_valid_deletion_removes_the_physical_file(): void
    {
        $user = $this->user();
        $token = $this->login($user);
        $file = File::factory()->for($user)->create();
        Storage::disk('uploads')->put($file->stored_path, 'contenu');

        $this->withToken($token)->deleteJson($this->url($file->id))->assertNoContent();

        Storage::disk('uploads')->assertMissing($file->stored_path);
    }

    public function test_an_expired_file_is_deletable(): void
    {
        $user = $this->user();
        $token = $this->login($user);
        $file = File::factory()->for($user)->expired()->create();

        $response = $this->withToken($token)->deleteJson($this->url($file->id));

        $response->assertNoContent();
        $this->assertDatabaseMissing('files', ['id' => $file->id]);
    }

    public function test_deletion_without_authentication_returns_401(): void
    {
        $file = File::factory()->create();

        $response = $this->deleteJson($this->url($file->id));

        $response->assertUnauthorized();
        $this->assertDatabaseHas('files', ['id' => $file->id]);
    }

    public function test_deletion_with_a_revoked_token_returns_401(): void
    {
        $user = $this->user();
        $token = $this->login($user);
        $file = File::factory()->for($user)->create();

        $this->withToken($token)->postJson('/api/auth/logout')->assertOk();
        $this->app['auth']->forgetGuards();
        $this->app['tymon.jwt']->unsetToken();
        $this->app['tymon.jwt.auth']->unsetToken();

        $response = $this->withToken($token)->deleteJson($this->url($file->id));

        $response->assertUnauthorized();
        $this->assertDatabaseHas('files', ['id' => $file->id]);
    }

    public function test_an_unknown_id_returns_404(): void
    {
        $token = $this->login($this->user());

        $response = $this->withToken($token)->deleteJson($this->url(999_999));

        $response->assertNotFound();
    }

    public function test_an_unknown_id_returns_the_contract_message(): void
    {
        $token = $this->login($this->user());

        $response = $this->withToken($token)->deleteJson($this->url(999_999));

        $response->assertExactJson(['message' => self::NOT_FOUND]);
    }

    public function test_deleting_another_users_file_returns_404(): void
    {
        $owner = $this->user();
        $other = $this->user();
        $token = $this->login($other);
        $file = File::factory()->for($owner)->create();

        $response = $this->withToken($token)->deleteJson($this->url($file->id));

        $response->assertNotFound();
        $this->assertDatabaseHas('files', ['id' => $file->id]);
    }

    /**
     * La colonne `id` est un `bigint` : sur PostgreSQL, comparer un `bigint`
     * à une chaîne qui n'en est pas une lève une erreur SQL avant même
     * d'atteindre le contrôleur (contrairement à SQLite, dont le typage
     * dynamique ne trouve simplement aucune ligne et masquait donc ce bogue
     * en local). Le contrôleur garde le contrat quel que soit le moteur.
     */
    public function test_a_non_numeric_id_returns_404(): void
    {
        $token = $this->login($this->user());

        $response = $this->withToken($token)->deleteJson($this->url('abc'));

        $response->assertNotFound();
    }

    /**
     * Verrou du contrat A2 (docs/architecture.md) : un identifiant
     * inexistant, non numérique, ou le fichier d'un autre compte doivent
     * être strictement indistinguables pour l'appelant, corps compris —
     * c'est la comparaison de corps, et non la construction interne, qui
     * tient cette garantie (cf. le docblock de FileNotFoundException).
     */
    public function test_an_unknown_id_a_non_numeric_id_and_another_users_file_return_the_same_body(): void
    {
        $owner = $this->user();
        $other = $this->user();
        $token = $this->login($other);
        $file = File::factory()->for($owner)->create();

        $unknownResponse = $this->withToken($token)->deleteJson($this->url(999_999));
        $nonNumericResponse = $this->withToken($token)->deleteJson($this->url('abc'));
        $othersFileResponse = $this->withToken($token)->deleteJson($this->url($file->id));

        $unknownResponse->assertExactJson(['message' => self::NOT_FOUND]);
        $nonNumericResponse->assertExactJson(['message' => self::NOT_FOUND]);
        $othersFileResponse->assertExactJson(['message' => self::NOT_FOUND]);
    }

    public function test_deleting_an_already_deleted_file_returns_404(): void
    {
        $user = $this->user();
        $token = $this->login($user);
        $file = File::factory()->for($user)->create();

        $this->withToken($token)->deleteJson($this->url($file->id))->assertNoContent();
        $response = $this->withToken($token)->deleteJson($this->url($file->id));

        $response->assertNotFound();
    }

    public function test_a_file_whose_content_is_already_missing_from_disk_is_still_deleted(): void
    {
        $user = $this->user();
        $token = $this->login($user);
        // Storage::fake('uploads') en setUp : aucun octet n'est écrit à
        // stored_path, la ligne est donc déjà orpheline avant l'appel.
        $file = File::factory()->for($user)->create();

        $response = $this->withToken($token)->deleteJson($this->url($file->id));

        $response->assertNoContent();
        $this->assertDatabaseMissing('files', ['id' => $file->id]);
    }

    public function test_deletion_response_is_not_storable(): void
    {
        $user = $this->user();
        $token = $this->login($user);
        $file = File::factory()->for($user)->create();

        $response = $this->withToken($token)->deleteJson($this->url($file->id));

        $response->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_deletion_is_capped_by_the_general_api_limiter(): void
    {
        $user = $this->user();
        $token = $this->login($user);
        $files = File::factory()->for($user)->count(61)->create();

        foreach ($files->take(60) as $file) {
            $this->withToken($token)->deleteJson($this->url($file->id))->assertNoContent();
        }

        $response = $this->withToken($token)->deleteJson($this->url($files->last()->id));

        $response->assertTooManyRequests();
        $response->assertHeader('Retry-After');
    }

    public function test_the_share_link_answers_404_after_deletion(): void
    {
        $user = $this->user();
        $token = $this->login($user);
        $file = File::factory()->for($user)->create();
        Storage::disk('uploads')->put($file->stored_path, 'contenu');

        $this->withToken($token)->deleteJson($this->url($file->id))->assertNoContent();

        $response = $this->getJson("/api/links/{$file->token}");

        $response->assertNotFound();
        $response->assertExactJson(['message' => 'Ce lien de téléchargement est invalide.']);
    }

    public function test_a_deleted_file_no_longer_appears_in_the_listing(): void
    {
        $user = $this->user();
        $token = $this->login($user);
        $file = File::factory()->for($user)->create();

        $this->withToken($token)->deleteJson($this->url($file->id))->assertNoContent();

        $response = $this->withToken($token)->getJson('/api/files');

        $response->assertOk();
        $response->assertJsonPath('data', []);
    }
}
