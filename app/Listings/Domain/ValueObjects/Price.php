<?php

declare(strict_types=1);

namespace App\Listings\Domain\ValueObjects;

use App\Listings\Domain\Exceptions\InvalidListingDataException;

/**
 * Monetary amount in USD (implicit, SPECS #19). Persisted as DECIMAL(10,2).
 * Stored internally as integer cents to avoid floating-point drift.
 * Rule: >= 0.01 and within DECIMAL(10,2) range. (SPECS #2 / §4.1)
 */
final class Price
{
    private const MIN_CENTS = 1;            // 0.01

    private const MAX_CENTS = 9_999_999_999; // 99,999,999.99 (DECIMAL(10,2))

    public readonly int $cents;

    public function __construct(int|float|string $amount)
    {
        if (is_string($amount)) {
            $amount = trim($amount);
            if (! is_numeric($amount)) {
                throw InvalidListingDataException::forField('price', 'The price must be numeric.');
            }
            $amount = (float) $amount;
        }

        $cents = (int) round(((float) $amount) * 100);

        if ($cents < self::MIN_CENTS) {
            throw InvalidListingDataException::forField('price', 'The price must be at least 0.01.');
        }

        if ($cents > self::MAX_CENTS) {
            throw InvalidListingDataException::forField('price', 'The price exceeds the maximum allowed value.');
        }

        $this->cents = $cents;
    }

    /** Decimal value for persistence/serialization, e.g. 199.99. */
    public function value(): float
    {
        return $this->cents / 100;
    }

    public function equals(self $other): bool
    {
        return $this->cents === $other->cents;
    }

    public function __toString(): string
    {
        return number_format($this->value(), 2, '.', '');
    }
}
