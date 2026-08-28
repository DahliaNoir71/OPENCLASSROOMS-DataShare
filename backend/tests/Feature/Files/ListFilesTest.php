<?php

namespace Tests\Feature\Files;

use App\Models\File;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CapturesLogs;
use Tests\TestCase;

class ListFilesTest extends TestCase
{
    use CapturesLogs;
    use RefreshDatabase;

    private const PASSWORD = 'password123';

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

    private function url(array $query = []): string
    {
        return '/api/files'.($query === [] ? '' : '?'.http_build_query($query));
    }

    public function test_the_listing_returns_the_contract_fields_for_each_item(): void
    {
        $user = $this->user();
        $token = $this->login($user);
        File::factory()->for($user)->create();

        $response = $this->withToken($token)->getJson($this->url());

        $response->assertOk();
        $response->assertJsonStructure([
            'data' => [
                '*' => [
                    'id', 'original_name', 'size', 'mime_type', 'protected',
                    'expires_at', 'expired', 'link', 'created_at',
                ],
            ],
            'links' => ['first', 'last', 'prev', 'next'],
            'meta',
        ]);
    }

    public function test_the_listing_returns_only_the_files_of_the_authenticated_user(): void
    {
        $user = $this->user();
        $other = $this->user();
        $token = $this->login($user);

        $mine = File::factory()->for($user)->create();
        File::factory()->for($other)->create();

        $response = $this->withToken($token)->getJson($this->url());

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.id', $mine->id);
    }

    public function test_the_listing_is_ordered_from_the_most_recent_deposit(): void
    {
        $user = $this->user();
        $token = $this->login($user);

        $older = $this->travelTo(now()->subDays(2), fn () => File::factory()->for($user)->create());
        $newer = $this->travelTo(now()->subDay(), fn () => File::factory()->for($user)->create());

        $response = $this->withToken($token)->getJson($this->url());

        $response->assertOk();
        $response->assertJsonPath('data.0.id', $newer->id);
        $response->assertJsonPath('data.1.id', $older->id);
    }

    public function test_the_listing_reports_expired_true_for_a_file_past_its_expiry(): void
    {
        $user = $this->user();
        $token = $this->login($user);
        File::factory()->for($user)->expired()->create();

        $response = $this->withToken($token)->getJson($this->url());

        $response->assertOk();
        $response->assertJsonPath('data.0.expired', true);
    }

    public function test_the_listing_reports_expired_false_for_an_active_file(): void
    {
        $user = $this->user();
        $token = $this->login($user);
        File::factory()->for($user)->create();

        $response = $this->withToken($token)->getJson($this->url());

        $response->assertOk();
        $response->assertJsonPath('data.0.expired', false);
    }

    public function test_the_listing_of_an_account_without_files_returns_an_empty_data_array(): void
    {
        $token = $this->login($this->user());

        $response = $this->withToken($token)->getJson($this->url());

        $response->assertOk();
        $response->assertJsonPath('data', []);
    }

    public function test_the_listing_never_exposes_the_stored_path_the_password_hash_or_the_owner_email(): void
    {
        $user = $this->user();
        $token = $this->login($user);
        $file = File::factory()->for($user)->protected('secret123')->create();

        $response = $this->withToken($token)->getJson($this->url());

        $response->assertOk();
        $body = $response->getContent();
        $this->assertStringNotContainsString($file->stored_path, $body);
        $this->assertStringNotContainsString($file->getRawOriginal('password'), $body);
        $this->assertStringNotContainsString($user->email, $body);
        $this->assertArrayNotHasKey('password', $response->json('data.0'));
        $this->assertArrayNotHasKey('stored_path', $response->json('data.0'));
        $this->assertArrayNotHasKey('token', $response->json('data.0'));
    }

    public function test_the_listing_exposes_the_share_link_containing_the_token(): void
    {
        $user = $this->user();
        $token = $this->login($user);
        $file = File::factory()->for($user)->create();

        $response = $this->withToken($token)->getJson($this->url());

        $response->assertOk();
        $link = $response->json('data.0.link');
        $this->assertStringStartsWith(rtrim(config('datashare.frontend_url'), '/').'/l/', $link);
        $this->assertStringEndsWith($file->token, $link);
    }

    public function test_the_listing_returns_the_data_links_and_meta_envelope(): void
    {
        $user = $this->user();
        $token = $this->login($user);
        File::factory()->for($user)->create();

        $response = $this->withToken($token)->getJson($this->url());

        $response->assertOk();
        $response->assertJsonStructure([
            'meta' => ['current_page', 'from', 'last_page', 'path', 'per_page', 'to', 'total'],
        ]);
    }

    public function test_the_listing_defaults_to_twenty_five_items_per_page(): void
    {
        $token = $this->login($this->user());

        $response = $this->withToken($token)->getJson($this->url());

        $response->assertOk();
        $response->assertJsonPath('meta.per_page', 25);
    }

    public function test_per_page_bounds_the_page_size(): void
    {
        $user = $this->user();
        $token = $this->login($user);
        File::factory()->for($user)->count(3)->create();

        $response = $this->withToken($token)->getJson($this->url(['per_page' => 2]));

        $response->assertOk();
        $response->assertJsonCount(2, 'data');
        $response->assertJsonPath('meta.per_page', 2);
        $response->assertJsonPath('meta.total', 3);
    }

    public function test_per_page_above_the_configured_maximum_returns_422(): void
    {
        config(['datashare.history.max_per_page' => 5]);

        $token = $this->login($this->user());

        $response = $this->withToken($token)->getJson($this->url(['per_page' => 6]));

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['per_page']);
    }

    public function test_per_page_of_zero_or_a_non_integer_returns_422(): void
    {
        $token = $this->login($this->user());

        $this->withToken($token)->getJson($this->url(['per_page' => 0]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['per_page']);

        $this->withToken($token)->getJson($this->url(['per_page' => 'abc']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['per_page']);
    }

    public function test_the_second_page_returns_the_following_items_without_overlap(): void
    {
        $user = $this->user();
        $token = $this->login($user);

        $first = $this->travelTo(now()->subDays(3), fn () => File::factory()->for($user)->create());
        $second = $this->travelTo(now()->subDays(2), fn () => File::factory()->for($user)->create());
        $third = $this->travelTo(now()->subDay(), fn () => File::factory()->for($user)->create());

        $pageOne = $this->withToken($token)->getJson($this->url(['per_page' => 2, 'page' => 1]));
        $pageOne->assertOk();
        $pageOne->assertJsonPath('data.0.id', $third->id);
        $pageOne->assertJsonPath('data.1.id', $second->id);

        $pageTwo = $this->withToken($token)->getJson($this->url(['per_page' => 2, 'page' => 2]));
        $pageTwo->assertOk();
        $pageTwo->assertJsonCount(1, 'data');
        $pageTwo->assertJsonPath('data.0.id', $first->id);
    }

    public function test_a_page_beyond_the_last_returns_an_empty_data_array_with_a_valid_meta(): void
    {
        $user = $this->user();
        $token = $this->login($user);
        File::factory()->for($user)->create();

        $response = $this->withToken($token)->getJson($this->url(['page' => 5]));

        $response->assertOk();
        $response->assertJsonPath('data', []);
        $response->assertJsonPath('meta.current_page', 5);
        $response->assertJsonPath('meta.total', 1);
    }

    /**
     * Sans `withQueryString()` sur le paginateur, `links.next` perdrait
     * silencieusement `status` et `per_page`, et suivre le lien reviendrait
     * aux valeurs par défaut plutôt qu'au filtre demandé.
     */
    public function test_the_pagination_links_preserve_the_status_and_per_page_parameters(): void
    {
        $user = $this->user();
        $token = $this->login($user);
        File::factory()->for($user)->count(3)->create();

        $response = $this->withToken($token)->getJson($this->url(['status' => 'active', 'per_page' => 2]));

        $response->assertOk();
        $next = $response->json('links.next');
        $this->assertNotNull($next);
        $this->assertStringContainsString('status=active', $next);
        $this->assertStringContainsString('per_page=2', $next);
    }

    public function test_status_defaults_to_all_and_returns_active_and_expired_files(): void
    {
        $user = $this->user();
        $token = $this->login($user);
        File::factory()->for($user)->create();
        File::factory()->for($user)->expired()->create();

        $response = $this->withToken($token)->getJson($this->url());

        $response->assertOk();
        $response->assertJsonCount(2, 'data');
    }

    public function test_status_active_returns_only_unexpired_files(): void
    {
        $user = $this->user();
        $token = $this->login($user);
        $active = File::factory()->for($user)->create();
        File::factory()->for($user)->expired()->create();

        $response = $this->withToken($token)->getJson($this->url(['status' => 'active']));

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.id', $active->id);
    }

    public function test_status_expired_returns_only_expired_files(): void
    {
        $user = $this->user();
        $token = $this->login($user);
        File::factory()->for($user)->create();
        $expired = File::factory()->for($user)->expired()->create();

        $response = $this->withToken($token)->getJson($this->url(['status' => 'expired']));

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.id', $expired->id);
    }

    public function test_the_status_filter_is_reflected_in_the_meta_total(): void
    {
        $user = $this->user();
        $token = $this->login($user);
        File::factory()->for($user)->count(2)->create();
        File::factory()->for($user)->expired()->count(3)->create();

        $this->withToken($token)->getJson($this->url(['status' => 'active']))
            ->assertJsonPath('meta.total', 2);

        $this->withToken($token)->getJson($this->url(['status' => 'expired']))
            ->assertJsonPath('meta.total', 3);

        $this->withToken($token)->getJson($this->url(['status' => 'all']))
            ->assertJsonPath('meta.total', 5);
    }

    public function test_an_unknown_status_value_returns_422(): void
    {
        $token = $this->login($this->user());

        $response = $this->withToken($token)->getJson($this->url(['status' => 'archived']));

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['status']);
    }

    public function test_a_non_string_status_returns_422_with_the_french_message(): void
    {
        $token = $this->login($this->user());

        $response = $this->withToken($token)->getJson('/api/files?status[]=x');

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['status']);
        $response->assertJsonPath('errors.status.0', 'Le filtre doit être une chaîne de caractères.');
    }

    public function test_the_listing_without_authentication_returns_401(): void
    {
        $response = $this->getJson($this->url());

        $response->assertUnauthorized();
        $response->assertExactJson(['message' => 'Authentification requise.']);
    }

    public function test_the_listing_with_a_revoked_token_returns_401(): void
    {
        $token = $this->login($this->user());
        $this->withToken($token)->postJson('/api/auth/logout')->assertOk();

        $this->app['auth']->forgetGuards();
        $this->app['tymon.jwt']->unsetToken();
        $this->app['tymon.jwt.auth']->unsetToken();

        $response = $this->withToken($token)->getJson($this->url());

        $response->assertUnauthorized();
    }

    public function test_the_listing_response_is_not_storable(): void
    {
        $token = $this->login($this->user());

        $this->withToken($token)->getJson($this->url())
            ->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_the_listing_is_capped_by_the_general_api_limiter(): void
    {
        $token = $this->login($this->user());

        foreach (range(1, 60) as $ignored) {
            $this->withToken($token)->getJson($this->url())->assertOk();
        }

        $response = $this->withToken($token)->getJson($this->url());

        $response->assertTooManyRequests();
        $response->assertHeader('Retry-After');
    }

    /**
     * L'inventaire ne relève jamais le disque : `size` fait foi, comme
     * `LinkMetadataResource` le fait déjà pour un anonyme.
     */
    public function test_the_listing_does_not_hit_the_disk(): void
    {
        $user = $this->user();
        $token = $this->login($user);
        File::factory()->for($user)->create();

        $this->withToken($token)->getJson($this->url())->assertOk();

        $this->assertEmpty(Storage::disk('uploads')->allFiles());
    }

    /**
     * Une lecture ne change rien : aucun événement de consultation ne figure
     * au tableau d'audit d'architecture.md.
     */
    public function test_the_listing_leaves_no_audit_line(): void
    {
        $user = $this->user();
        $token = $this->login($user);
        File::factory()->for($user)->create();

        // Compté avant l'appel : la connexion qui précède a déjà écrit sa
        // propre ligne, hors de propos ici.
        $countBefore = count($this->allLogs());

        $this->withToken($token)->getJson($this->url())->assertOk();

        $this->assertCount($countBefore, $this->allLogs());
    }
}
