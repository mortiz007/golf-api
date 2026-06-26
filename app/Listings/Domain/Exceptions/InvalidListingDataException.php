<?php

declare(strict_types=1);

namespace App\Listings\Domain\Exceptions;

/**
 * Raised when listing data fails a domain rule.
 *
 * Carries a field-keyed error bag aligned with the API error contract
 * (SPECS §4): error.details = { "field": ["message", ...] }.
 * The HTTP layer (S1-14/S1-15) maps this to a 422 response.
 */
final class InvalidListingDataException extends ListingDomainException
{
    /** @var array<string, array<int, string>> */
    private array $errors;

    /**
     * @param  array<string, array<int, string>>  $errors
     */
    public function __construct(array $errors, string $message = 'Invalid listing data.')
    {
        $this->errors = $errors;
        parent::__construct($message);
    }

    /** Convenience constructor for a single field/message (used by VOs since S1-01). */
    public static function forField(string $field, string $message): self
    {
        return new self([$field => [$message]], $message);
    }

    /**
     * @param  array<string, array<int, string>>  $errors
     */
    public static function withErrors(array $errors): self
    {
        return new self($errors);
    }

    /**
     * Field-keyed error details, ready for the API error.details payload.
     *
     * @return array<string, array<int, string>>
     */
    public function errors(): array
    {
        return $this->errors;
    }
}
