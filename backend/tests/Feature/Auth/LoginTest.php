<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_valid_login_returns_200_with_token_and_user(): void
    {
        $user = User::factory()->create([
            'email' => 'jane.doe@example.com',
            'password' => 'password123',
        ]);

        $response = $this->postJson('/api/auth/login', [
            'email' => 'jane.doe@example.com',
            'password' => 'password123',
        ]);

        $response->assertOk();
        $response->assertJsonStructure(['token', 'user' => ['id', 'email']]);
        $response->assertJsonPath('user.id', $user->id);
        $response->assertJsonPath('user.email', 'jane.doe@example.com');
    }

    public function test_valid_login_response_user_has_only_id_and_email(): void
    {
        User::factory()->create([
            'email' => 'jane.doe@example.com',
            'password' => 'password123',
        ]);

        $response = $this->postJson('/api/auth/login', [
            'email' => 'jane.doe@example.com',
            'password' => 'password123',
        ]);

        $response->assertOk();
        $response->assertJson(fn ($json) => $json->hasAll(['token', 'user'])
            ->has('user', fn ($json) => $json->hasAll(['id', 'email'])->etc(false))
        );
    }

    public function test_returned_token_is_a_valid_jwt_whose_sub_claim_is_the_user_id(): void
    {
        $user = User::factory()->create([
            'email' => 'jane.doe@example.com',
            'password' => 'password123',
        ]);

        $response = $this->postJson('/api/auth/login', [
            'email' => 'jane.doe@example.com',
            'password' => 'password123',
        ]);

        $response->assertOk();

        $payload = JWTAuth::setToken($response->json('token'))->getPayload();

        $this->assertSame((string) $user->id, (string) $payload->get('sub'));
    }

    public function test_login_with_a_differently_cased_email_returns_200(): void
    {
        $user = User::factory()->create([
            'email' => 'jane.doe@example.com',
            'password' => 'password123',
        ]);

        $response = $this->postJson('/api/auth/login', [
            'email' => '  Jane.DOE@Example.COM  ',
            'password' => 'password123',
        ]);

        $response->assertOk();
        $response->assertJsonPath('user.id', $user->id);
        $response->assertJsonPath('user.email', 'jane.doe@example.com');
    }

    public function test_login_with_wrong_password_returns_401(): void
    {
        User::factory()->create([
            'email' => 'jane.doe@example.com',
            'password' => 'password123',
        ]);

        $response = $this->postJson('/api/auth/login', [
            'email' => 'jane.doe@example.com',
            'password' => 'wrong-password',
        ]);

        $response->assertUnauthorized();
        $response->assertJsonStructure(['message']);
    }

    public function test_login_with_unknown_email_returns_401(): void
    {
        $response = $this->postJson('/api/auth/login', [
            'email' => 'nobody@example.com',
            'password' => 'password123',
        ]);

        $response->assertUnauthorized();
        $response->assertJsonStructure(['message']);
    }

    /**
     * Anti-enumeration: the two failure modes must be indistinguishable from
     * the outside, otherwise the endpoint tells an attacker which addresses
     * hold an account.
     */
    public function test_unknown_email_and_wrong_password_return_the_same_body(): void
    {
        User::factory()->create([
            'email' => 'jane.doe@example.com',
            'password' => 'password123',
        ]);

        $wrongPassword = $this->postJson('/api/auth/login', [
            'email' => 'jane.doe@example.com',
            'password' => 'wrong-password',
        ]);

        $unknownEmail = $this->postJson('/api/auth/login', [
            'email' => 'nobody@example.com',
            'password' => 'password123',
        ]);

        $wrongPassword->assertUnauthorized();
        $unknownEmail->assertUnauthorized();
        $this->assertSame($wrongPassword->json(), $unknownEmail->json());
    }

    public function test_login_with_invalid_email_returns_422(): void
    {
        $response = $this->postJson('/api/auth/login', [
            'email' => 'not-an-email',
            'password' => 'password123',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['email']);
    }

    public function test_login_with_missing_email_returns_422(): void
    {
        $response = $this->postJson('/api/auth/login', [
            'password' => 'password123',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['email']);
    }

    public function test_login_with_missing_password_returns_422(): void
    {
        $response = $this->postJson('/api/auth/login', [
            'email' => 'jane.doe@example.com',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['password']);
    }

    public function test_login_is_throttled_after_five_attempts_from_the_same_ip(): void
    {
        User::factory()->create([
            'email' => 'jane.doe@example.com',
            'password' => 'password123',
        ]);

        foreach (range(1, 5) as $ignored) {
            $this->postJson('/api/auth/login', [
                'email' => 'jane.doe@example.com',
                'password' => 'wrong-password',
            ])->assertUnauthorized();
        }

        $response = $this->postJson('/api/auth/login', [
            'email' => 'jane.doe@example.com',
            'password' => 'password123',
        ]);

        $response->assertTooManyRequests();
    }
}
