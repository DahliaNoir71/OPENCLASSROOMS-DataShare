<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class RegisterTest extends TestCase
{
    use RefreshDatabase;

    public function test_valid_registration_returns_201_with_token_and_user(): void
    {
        $response = $this->postJson('/api/auth/register', [
            'email' => 'jane.doe@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertCreated();
        $response->assertJsonStructure(['token', 'user' => ['id', 'email']]);
        $response->assertJsonPath('user.email', 'jane.doe@example.com');

        $this->assertDatabaseHas('users', ['email' => 'jane.doe@example.com']);

        $user = User::where('email', 'jane.doe@example.com')->first();
        $this->assertNotEquals('password123', $user->password);
        $this->assertTrue(Hash::check('password123', $user->password));
    }

    public function test_valid_registration_response_user_has_only_id_and_email(): void
    {
        $response = $this->postJson('/api/auth/register', [
            'email' => 'jane.doe@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertCreated();
        $response->assertJson(fn ($json) => $json->hasAll(['token', 'user'])
            ->has('user', fn ($json) => $json->hasAll(['id', 'email'])->etc(false))
        );
    }

    public function test_registration_with_already_used_email_returns_422(): void
    {
        User::factory()->create(['email' => 'jane.doe@example.com']);

        $response = $this->postJson('/api/auth/register', [
            'email' => 'jane.doe@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['email']);
        $this->assertSame(1, User::where('email', 'jane.doe@example.com')->count());
    }

    public function test_registration_stores_the_email_lowercased(): void
    {
        $response = $this->postJson('/api/auth/register', [
            'email' => '  Jane.DOE@Example.COM  ',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('user.email', 'jane.doe@example.com');
        $this->assertDatabaseHas('users', ['email' => 'jane.doe@example.com']);
    }

    public function test_registration_with_an_email_differing_only_by_case_returns_422(): void
    {
        User::factory()->create(['email' => 'jane.doe@example.com']);

        $response = $this->postJson('/api/auth/register', [
            'email' => 'Jane.DOE@Example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['email']);
        $this->assertSame(1, User::count());
    }

    public function test_registration_with_a_non_string_email_returns_422(): void
    {
        $response = $this->postJson('/api/auth/register', [
            'email' => ['jane.doe@example.com'],
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['email']);
    }

    public function test_registration_with_an_overlong_email_returns_422(): void
    {
        $response = $this->postJson('/api/auth/register', [
            'email' => str_repeat('a', 250).'@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['email']);
    }

    public function test_registration_is_throttled_after_five_attempts_from_the_same_ip(): void
    {
        foreach (range(1, 5) as $i) {
            $this->postJson('/api/auth/register', [
                'email' => "user{$i}@example.com",
                'password' => 'password123',
                'password_confirmation' => 'password123',
            ])->assertCreated();
        }

        $response = $this->postJson('/api/auth/register', [
            'email' => 'user6@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertTooManyRequests();
        $this->assertDatabaseMissing('users', ['email' => 'user6@example.com']);
    }

    public function test_registration_with_invalid_email_returns_422(): void
    {
        $response = $this->postJson('/api/auth/register', [
            'email' => 'not-an-email',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['email']);
    }

    public function test_registration_with_password_shorter_than_8_characters_returns_422(): void
    {
        $response = $this->postJson('/api/auth/register', [
            'email' => 'jane.doe@example.com',
            'password' => 'short12',
            'password_confirmation' => 'short12',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['password']);
    }

    public function test_registration_with_missing_password_confirmation_returns_422(): void
    {
        $response = $this->postJson('/api/auth/register', [
            'email' => 'jane.doe@example.com',
            'password' => 'password123',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['password']);
    }

    public function test_registration_with_mismatched_password_confirmation_returns_422(): void
    {
        $response = $this->postJson('/api/auth/register', [
            'email' => 'jane.doe@example.com',
            'password' => 'password123',
            'password_confirmation' => 'different123',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['password']);
    }
}
