<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NoStoreHeaderTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Symfony re-serialises cache directives in alphabetical order, so the
     * header goes out as "no-store, private" whatever order we set it in.
     */
    private const NO_STORE = 'no-store, private';

    public function test_a_plain_response_is_not_storable(): void
    {
        $response = $this->getJson('/api/ping');

        $response->assertOk();
        $response->assertHeader('Cache-Control', self::NO_STORE);
    }

    public function test_a_response_carrying_a_token_is_not_storable(): void
    {
        $response = $this->postJson('/api/auth/register', $this->credentials(1));

        $response->assertCreated();
        $response->assertJsonStructure(['token']);
        $response->assertHeader('Cache-Control', self::NO_STORE);
    }

    public function test_a_validation_error_is_not_storable(): void
    {
        $response = $this->postJson('/api/auth/register', ['email' => 'not-an-email']);

        $response->assertUnprocessable();
        $response->assertHeader('Cache-Control', self::NO_STORE);
    }

    /**
     * A 429 is built from an exception rather than returned by a controller.
     * It still travels back through the middleware, and must still carry
     * Retry-After, which the API contract promises.
     */
    public function test_a_throttled_response_is_not_storable_and_keeps_retry_after(): void
    {
        foreach (range(1, 5) as $i) {
            $this->postJson('/api/auth/register', $this->credentials($i))->assertCreated();
        }

        $response = $this->postJson('/api/auth/register', $this->credentials(6));

        $response->assertTooManyRequests();
        $response->assertHeader('Cache-Control', self::NO_STORE);
        $response->assertHeader('Retry-After');
    }

    /**
     * @return array<string, string>
     */
    private function credentials(int $index): array
    {
        return [
            'email' => "user{$index}@example.com",
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ];
    }
}
