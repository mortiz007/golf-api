<?php

declare(strict_types=1);

namespace App\Listings\Domain\Exceptions;

/**
 * Raised when an actor tries to modify a listing they do not own (SPECS §4.2).
 *
 * Defensive owner-only re-check inside the use case (DESIGN §III). Maps to
 * HTTP 403 (FORBIDDEN) at the boundary (bootstrap/app.php).
 */
final class ListingAccessDeniedException extends ListingDomainException
{
    public static function forListing(int $listingId): self
    {
        return new self("You are not allowed to modify listing {$listingId}.");
    }
}
