<?php

declare(strict_types=1);

namespace App\AuditLog\Domain\ValueObjects;

use InvalidArgumentException;

/**
 * Human-readable audit message, e.g. "Created listing 'Driver X' (id: 123) by user 45".
 *
 * Non-empty and bounded to the `message` column length (VARCHAR(500)).
 */
final class AuditMessage
{
    private const MAX = 500;

    public readonly string $value;

    public function __construct(string $value)
    {
        $value = trim($value);

        if ($value === '') {
            throw new InvalidArgumentException('The audit message must not be empty.');
        }

        if (mb_strlen($value) > self::MAX) {
            throw new InvalidArgumentException('The audit message must not exceed 500 characters.');
        }

        $this->value = $value;
    }

    /**
     * Builds the normative legible message for a listing audit fact
     * (SPECS §5 / DESIGN §IV.3): "Created listing 'X' (id: N) by user M".
     *
     * When $changedFields is provided (updates), the changed field names are
     * listed: "Updated listing 'X' (id: N): title, price by user M".
     *
     * @param  list<string>  $changedFields
     */
    public static function forListing(AuditAction $action, string $title, int $listingId, int $userId, array $changedFields = []): self
    {
        $fields = $changedFields !== []
            ? ': '.implode(', ', $changedFields)
            : '';

        return new self(sprintf(
            "%s listing '%s' (id: %d)%s by user %d",
            $action->verb(),
            $title,
            $listingId,
            $fields,
            $userId,
        ));
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
