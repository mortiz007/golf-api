<?php

declare(strict_types=1);

namespace App\AuditLog\Domain\ValueObjects;

use InvalidArgumentException;

/**
 * Event identifier used for idempotency (UUID v4).
 *
 * Framework-agnostic: the value originates from the domain event payload and is
 * validated here. A duplicate event_id is ignored downstream by the repository.
 */
final class EventId
{
    private const PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i';

    public readonly string $value;

    public function __construct(string $value)
    {
        $value = strtolower(trim($value));

        if (preg_match(self::PATTERN, $value) !== 1) {
            throw new InvalidArgumentException('The event id must be a valid UUID v4.');
        }

        $this->value = $value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
