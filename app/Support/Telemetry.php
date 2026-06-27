<?php

declare(strict_types=1);

namespace App\Support;

use Psr\Log\LoggerInterface;

/**
 * Central emitter for operational telemetry (DESIGN §9).
 *
 * Wraps a single structured logging channel (the dedicated `stdout` JSON Lines
 * channel) so the boundary layers — controllers, jobs and the LLM adapter —
 * emit events with a uniform shape. This is operational telemetry only: it is
 * independent of the AuditLog bounded context and must never carry user content
 * (title, description) or secrets (email, password, token).
 */
final class Telemetry
{
    public function __construct(private readonly LoggerInterface $logger) {}

    /**
     * Emit a structured event using the `<area>.<event>` naming convention.
     *
     * @param  array<string, scalar|null>  $context
     */
    public function event(string $event, array $context = [], string $level = 'info'): void
    {
        $this->logger->log($level, $event, $context);
    }
}
