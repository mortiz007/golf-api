<?php

declare(strict_types=1);

namespace App\Listings\Domain\ValueObjects;

use App\Listings\Domain\Exceptions\InvalidListingDataException;

/**
 * Listing condition. One of four fixed values. (SPECS §3 ENUM)
 */
final class ListingCondition
{
    public const NEW = 'New';

    public const USED = 'Used';

    public const REFURBISHED = 'Refurbished';

    public const LIKE_NEW = 'Like New';

    private const ALLOWED = [
        self::NEW,
        self::USED,
        self::REFURBISHED,
        self::LIKE_NEW,
    ];

    public readonly string $value;

    public function __construct(string $value)
    {
        if (! in_array($value, self::ALLOWED, true)) {
            throw InvalidListingDataException::forField(
                'condition',
                'The condition must be one of: New, Used, Refurbished, Like New.'
            );
        }

        $this->value = $value;
    }

    /** @return array<int, string> */
    public static function allowed(): array
    {
        return self::ALLOWED;
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
