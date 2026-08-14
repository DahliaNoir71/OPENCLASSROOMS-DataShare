<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CapturesLogs;
use Tests\TestCase;

class AuditTrailTest extends TestCase
{
    use CapturesLogs;
    use RefreshDatabase;

    private const REGISTER_URI = '/api/auth/register';

    private const LOGIN_URI = '/api/auth/login';

    private const MESSAGE = 'User registered';

    private const EMAIL = 'jane.doe@example.com';

    protected function setUp(): void
    {
        parent::setUp();

        $this->captureLogs();
    }

    public function test_a_successful_registration_leaves_an_audit_line(): void
    {
        $this->postJson(self::REGISTER_URI, $this->credentials())->assertCreated();

        $user = User::where('email', self::EMAIL)->firstOrFail();
        $lines = $this->logsWithMessage(self::MESSAGE);

        $this->assertCount(1, $lines);
        $this->assertSame('info', $lines[0]->level);
        $this->assertSame(['user_id' => $user->id], $lines[0]->context);
    }

    public function test_a_refused_registration_leaves_no_audit_line(): void
    {
        User::factory()->create(['email' => self::EMAIL]);

        $this->postJson(self::REGISTER_URI, $this->credentials())->assertUnprocessable();

        $this->assertSame([], $this->logsWithMessage(self::MESSAGE));
    }

    /**
     * A row created by a factory or a seeder is not a registration. The audit
     * trail records business actions taken through the API, which is why the
     * line is written by the controller rather than by a model observer.
     */
    public function test_creating_a_user_outside_the_api_leaves_no_audit_line(): void
    {
        User::factory()->create();

        $this->assertSame([], $this->logsWithMessage(self::MESSAGE));
    }

    public function test_a_successful_login_leaves_an_audit_line(): void
    {
        $user = User::factory()->create([
            'email' => self::EMAIL,
            'password' => 'password123',
        ]);

        $this->postJson(self::LOGIN_URI, [
            'email' => self::EMAIL,
            'password' => 'password123',
        ])->assertOk();

        $lines = $this->logsWithMessage('User logged in');

        $this->assertCount(1, $lines);
        $this->assertSame('info', $lines[0]->level);
        $this->assertSame(['user_id' => $user->id], $lines[0]->context);
    }

    /**
     * A failed sign-in is worth a line — it is the signal a credential
     * stuffing run leaves behind — but the line must not name the account
     * tried, or the log file becomes the enumeration oracle the response
     * refuses to be.
     */
    public function test_a_failed_login_leaves_an_audit_line_without_the_email(): void
    {
        User::factory()->create([
            'email' => self::EMAIL,
            'password' => 'password123',
        ]);

        $this->postJson(self::LOGIN_URI, [
            'email' => self::EMAIL,
            'password' => 'wrong-password',
        ])->assertUnauthorized();

        $lines = $this->logsWithMessage('Login failed');

        $this->assertCount(1, $lines);
        $this->assertSame('warning', $lines[0]->level);
        $this->assertSame(['ip'], array_keys($lines[0]->context));
        $this->assertStringNotContainsString(self::EMAIL, json_encode($lines[0]->context));
        $this->assertSame([], $this->logsWithMessage('User logged in'));
    }

    public function test_a_logout_leaves_an_audit_line(): void
    {
        $user = User::factory()->create([
            'email' => self::EMAIL,
            'password' => 'password123',
        ]);

        $token = $this->postJson(self::LOGIN_URI, [
            'email' => self::EMAIL,
            'password' => 'password123',
        ])->json('token');

        $this->withToken($token)->postJson('/api/auth/logout')->assertOk();

        $lines = $this->logsWithMessage('User logged out');

        $this->assertCount(1, $lines);
        $this->assertSame('info', $lines[0]->level);
        $this->assertSame(['user_id' => $user->id], $lines[0]->context);
    }

    /**
     * @return array<string, string>
     */
    private function credentials(): array
    {
        return [
            'email' => self::EMAIL,
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ];
    }
}
