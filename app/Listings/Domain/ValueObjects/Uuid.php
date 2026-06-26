<?php

declare(strict_types=1);

namespace App\Listings\Domain\ValueObjects;

/**
 * Minimal framework-agnostic UUID v4 generator/holder for event identity.
 * Avoids coupling the Domain to Laravel's Str::uuid().
 */
final class Uuid
{
    public readonly string $value;

    public function __construct(string $value)
    {
        $this->value = $value;
    }

    /** Generates a RFC 4122 version 4 UUID using PHP-native randomness. */
    public static function v4(): self
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40); // version 4
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80); // variant 10

        $hex = bin2hex($bytes);

        return new self(sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12),
        ));
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
