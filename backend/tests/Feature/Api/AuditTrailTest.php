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
