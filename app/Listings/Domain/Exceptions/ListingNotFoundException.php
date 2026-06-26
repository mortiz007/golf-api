<?php

declare(strict_types=1);

namespace App\Listings\Domain\Exceptions;

/**
 * Raised when a listing does not exist or has been cancelled (SPECS §4.2/§4.3).
 *
 * Cancelled listings are treated as not found. Maps to HTTP 404 (NOT_FOUND) at
 * the boundary (bootstrap/app.php).
 */
final class ListingNotFoundException extends ListingDomainException
{
    public static function withId(int $listingId): self
    {
        return new self("Listing {$listingId} was not found.");
    }
}
