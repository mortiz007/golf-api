<?php

declare(strict_types=1);

namespace App\Listings\Domain\ValueObjects;

use App\Listings\Domain\Exceptions\InvalidListingDataException;

/**
 * Listing description. Required, 10–1000 chars, sanitized. (SPECS #21)
 * Sanitization here is defensive (strip tags + trim); HTTP-layer sanitization
 * is also applied in S1-14.
 */
final class Description
{
    private const MIN = 10;

    private const MAX = 1000;

    public readonly string $value;

    public function __construct(string $value)
    {
        $value = trim(strip_tags($value));

        if ($value === '') {
            throw InvalidListingDataException::forField('description', 'The description is required.');
        }

        $length = mb_strlen($value);
        if ($length < self::MIN || $length > self::MAX) {
            throw InvalidListingDataException::forField(
                'description',
                'The description must be between 10 and 1000 characters.'
            );
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
