<?php

declare(strict_types=1);

namespace App\Listings\Application\Commands;

/**
 * Immutable input DTO for the UpdateListing use case (SPECS §4.2).
 *
 * Carries the authenticated actor, the target listing id, and ONLY the fields
 * present in the partial PATCH payload (already validated by UpdateListingRequest).
 * Keeping just the present keys lets the use case distinguish an absent field
 * from an explicit `end_date: null`.
 */
final class UpdateListingCommand
{
    /**
     * @param  array<string, mixed>  $changes  Validated payload, present keys only.
     */
    public function __construct(
        public readonly int $actorUserId,
        public readonly int $listingId,
        public readonly array $changes,
    ) {}

    /**
     * @param  array<string, mixed>  $validated  Validated request data (present keys only).
     */
    public static function fromArray(int $actorUserId, int $listingId, array $validated): self
    {
        return new self(
            actorUserId: $actorUserId,
            listingId: $listingId,
            changes: $validated,
        );
    }

    public function has(string $field): bool
    {
        return array_key_exists($field, $this->changes);
    }
}
