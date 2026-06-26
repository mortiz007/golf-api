<?php

declare(strict_types=1);

namespace App\Listings\Domain\ValueObjects;

use App\Listings\Domain\Exceptions\InvalidListingDataException;

/**
 * Listing title. Letters and spaces only, 3–255 chars, trimmed. (SPECS #2)
 */
final class Title
{
    private const PATTERN = '/^[A-Za-z ]+$/';

    private const MIN = 3;

    private const MAX = 255;

    public readonly string $value;

    public function __construct(string $value)
    {
        $value = trim($value);

        if ($value === '') {
            throw InvalidListingDataException::forField('title', 'The title is required.');
        }

        $length = mb_strlen($value);
        if ($length < self::MIN || $length > self::MAX) {
            throw InvalidListingDataException::forField(
                'title',
                'The title must be between 3 and 255 characters.'
            );
        }

        if (preg_match(self::PATTERN, $value) !== 1) {
            throw InvalidListingDataException::forField(
                'title',
                'The title may only contain letters and spaces.'
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
