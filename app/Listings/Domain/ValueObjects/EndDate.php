<?php

declare(strict_types=1);

namespace App\Listings\Domain\ValueObjects;

use App\Listings\Domain\Exceptions\InvalidListingDataException;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Optional end date. ISO 8601 date (YYYY-MM-DD), must be >= today. (SPECS #3)
 * This VO is only constructed when a value is present; null is handled upstream.
 */
final class EndDate
{
    public readonly DateTimeImmutable $value;

    public function __construct(string $value)
    {
        $value = trim($value);

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('UTC'));
        $errors = DateTimeImmutable::getLastErrors();

        if ($date === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            throw InvalidListingDataException::forField(
                'end_date',
                'The end date must be a valid ISO 8601 date (YYYY-MM-DD).'
            );
        }

        $today = new DateTimeImmutable('today', new DateTimeZone('UTC'));
        if ($date < $today) {
            throw InvalidListingDataException::forField('end_date', 'The end date must be today or later.');
        }

        $this->value = $date;
    }

    public function toString(): string
    {
        return $this->value->format('Y-m-d');
    }

    public function equals(self $other): bool
    {
        return $this->toString() === $other->toString();
    }

    public function __toString(): string
    {
        return $this->toString();
    }
}
