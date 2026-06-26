<?php

declare(strict_types=1);

namespace App\Listings\Domain\Exceptions;

/**
 * Raised when a business rule (not input validation) is violated within the
 * domain — e.g. defensive owner-only re-check in a use case (DESIGN §III).
 *
 * Not strictly required by POST /api/listings, but part of the domain
 * exception hierarchy. Maps to 403/409 at the boundary as appropriate.
 */
final class ListingRuleViolationException extends ListingDomainException {}
