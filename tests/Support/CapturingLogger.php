<?php

declare(strict_types=1);

namespace Tests\Support;

use Psr\Log\AbstractLogger;
use Stringable;

/**
 * In-memory PSR logger used to assert on structured telemetry events without
 * writing to stdout during the test suite.
 */
final class CapturingLogger extends AbstractLogger
{
    /** @var array<int, array{level: mixed, event: string, context: array<string, mixed>}> */
    public array $events = [];

    /**
     * @param  array<string, mixed>  $context
     */
    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->events[] = [
            'level' => $level,
            'event' => (string) $message,
            'context' => $context,
        ];
    }

    /**
     * @return array<int, array{level: mixed, event: string, context: array<string, mixed>}>
     */
    public function eventsNamed(string $event): array
    {
        return array_values(array_filter($this->events, static fn (array $entry): bool => $entry['event'] === $event));
    }
}
