<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CapturesLogs;
use Tests\TestCase;

class RateLimitLoggingTest extends TestCase
{
    use CapturesLogs;
    use RefreshDatabase;

    private const REGISTER_URI = '/api/auth/register';

    private const MESSAGE = 'Rate limit exceeded';

    protected function setUp(): void
    {
        parent::setUp();

        $this->captureLogs();
    }

    public function test_exceeding_the_auth_limiter_writes_a_warning(): void
    {
        $this->exhaustAuthLimiter();

        $warnings = $this->logsWithMessage(self::MESSAGE);

        $this->assertCount(1, $warnings);
        $this->assertSame('warning', $warnings[0]->level);
        $this->assertSame('auth', $warnings[0]->context['limiter']);
        $this->assertSame('api/auth/register', $warnings[0]->context['route']);
    }

    public function test_a_request_within_the_limit_writes_no_warning(): void
    {
        $this->postJson(self::REGISTER_URI, $this->credentials(1))->assertCreated();

        $this->assertSame([], $this->logsWithMessage(self::MESSAGE));
    }

    /**
     * The rule the architecture doc states: no share token, no JWT, no
     * password, no email address in a log line. Only numeric identifiers, the
     * route pattern, and the caller address.
     */
    public function test_the_logged_context_carries_no_personal_data(): void
    {
        $this->exhaustAuthLimiter();

        $context = $this->logsWithMessage(self::MESSAGE)[0]->context;

        $this->assertSame(
            ['limiter', 'ip', 'method', 'route', 'user_id'],
            array_keys($context),
        );
        $this->assertStringNotContainsString('@example.com', json_encode($context));
    }

    private function exhaustAuthLimiter(): void
    {
        foreach (range(1, 5) as $i) {
            $this->postJson(self::REGISTER_URI, $this->credentials($i))->assertCreated();
        }

        $this->postJson(self::REGISTER_URI, $this->credentials(6))->assertTooManyRequests();
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
