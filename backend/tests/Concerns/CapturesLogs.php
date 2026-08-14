<?php

namespace Tests\Concerns;

use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Log;

/**
 * Captures log lines as they are written, without mocking the logger: the
 * MessageLogged event fires whatever channel is configured, so the assertions
 * hold on the real code path.
 */
trait CapturesLogs
{
    /**
     * @var list<MessageLogged>
     */
    private array $capturedLogs = [];

    protected function captureLogs(): void
    {
        Log::listen(function (MessageLogged $logged): void {
            $this->capturedLogs[] = $logged;
        });
    }

    /**
     * @return list<MessageLogged>
     */
    protected function logsWithMessage(string $message): array
    {
        return array_values(array_filter(
            $this->capturedLogs,
            fn (MessageLogged $logged): bool => $logged->message === $message,
        ));
    }
}
