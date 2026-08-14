<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class RateLimitLoggingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @var list<MessageLogged>
     */
    private array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();

        Log::listen(function (MessageLogged $message): void {
            $this->logged[] = $message;
        });
    }

    public function test_exceeding_the_auth_limiter_writes_a_warning(): void
    {
        $this->exhaustAuthLimiter();

        $warnings = $this->rateLimitWarnings();

        $this->assertCount(1, $warnings);
        $this->assertSame('warning', $warnings[0]->level);
        $this->assertSame('auth', $warnings[0]->context['limiter']);
        $this->assertSame('api/auth/register', $warnings[0]->context['route']);
    }

    public function test_a_request_within_the_limit_writes_nothing(): void
    {
        $this->postJson('/api/auth/register', $this->credentials(1))->assertCreated();

        $this->assertSame([], $this->rateLimitWarnings());
    }

    /**
     * The rule the architecture doc states: no share token, no JWT, no
     * password, no email address in a log line. Only the numeric identifiers
     * and the route pattern.
     */
    public function test_the_logged_context_carries_no_personal_data(): void
    {
        $this->exhaustAuthLimiter();

        $context = $this->rateLimitWarnings()[0]->context;

        $this->assertSame(
            ['limiter', 'ip', 'method', 'route', 'user_id'],
            array_keys($context),
        );
        $this->assertStringNotContainsString('@example.com', json_encode($context));
    }

    /**
     * @return list<MessageLogged>
     */
    private function rateLimitWarnings(): array
    {
        return array_values(array_filter(
            $this->logged,
            fn (MessageLogged $message): bool => $message->message === 'Rate limit exceeded',
        ));
    }

    private function exhaustAuthLimiter(): void
    {
        foreach (range(1, 5) as $i) {
            $this->postJson('/api/auth/register', $this->credentials($i))->assertCreated();
        }

        $this->postJson('/api/auth/register', $this->credentials(6))->assertTooManyRequests();
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
