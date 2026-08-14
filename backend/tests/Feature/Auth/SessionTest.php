<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Lifecycle of an issued token: what it grants (GET /me) and how it is taken
 * back (POST /logout). Sign-in itself is covered by LoginTest.
 */
class SessionTest extends TestCase
{
    use RefreshDatabase;

    private function login(User $user): string
    {
        $response = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password123',
        ]);

        $response->assertOk();

        // In production every request boots its own application; in a test the
        // container survives from one request to the next, so the jwt guard
        // would keep the user it resolved while signing in and answer the
        // following requests without ever looking at the token presented.
        // Forgetting the guards is what makes these assertions about the token.
        $this->forgetGuards();

        return $response->json('token');
    }

    /**
     * Two caches survive a request inside a test and would both answer from
     * memory instead of from the header presented: the resolved guard, and the
     * jwt singleton, which parses the token once and keeps it.
     */
    private function forgetGuards(): void
    {
        $this->app['auth']->forgetGuards();
        $this->app['tymon.jwt']->unsetToken();
        $this->app['tymon.jwt.auth']->unsetToken();
    }

    private function user(): User
    {
        return User::factory()->create([
            'email' => 'jane.doe@example.com',
            'password' => 'password123',
        ]);
    }

    public function test_me_with_the_login_token_returns_the_authenticated_user(): void
    {
        $user = $this->user();
        $token = $this->login($user);

        $response = $this->withToken($token)->getJson('/api/auth/me');

        $response->assertOk();
        $response->assertJsonPath('user.id', $user->id);
        $response->assertJsonPath('user.email', 'jane.doe@example.com');
        $response->assertJson(fn ($json) => $json->has('user',
            fn ($json) => $json->hasAll(['id', 'email'])->etc(false)
        )->etc(false));
    }

    public function test_me_without_an_authorization_header_returns_401(): void
    {
        $response = $this->getJson('/api/auth/me');

        $response->assertUnauthorized();
        $response->assertExactJson(['message' => 'Authentification requise.']);
    }

    public function test_me_with_a_tampered_token_returns_401(): void
    {
        $token = $this->login($this->user());

        $response = $this->withToken($token.'tampered')->getJson('/api/auth/me');

        $response->assertUnauthorized();
    }

    public function test_logout_with_a_valid_token_returns_200(): void
    {
        $token = $this->login($this->user());

        $response = $this->withToken($token)->postJson('/api/auth/logout');

        $response->assertOk();
        $response->assertJsonStructure(['message']);
    }

    /**
     * The point of logging out server-side: the token is revoked, not merely
     * dropped by the client. Replaying it must fail.
     */
    public function test_the_token_no_longer_authenticates_after_logout(): void
    {
        $token = $this->login($this->user());

        $this->withToken($token)->postJson('/api/auth/logout')->assertOk();
        $this->forgetGuards();

        $response = $this->withToken($token)->getJson('/api/auth/me');

        $response->assertUnauthorized();
    }

    public function test_logout_without_a_token_returns_401(): void
    {
        $response = $this->postJson('/api/auth/logout');

        $response->assertUnauthorized();
    }
}
