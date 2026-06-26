<?php

declare(strict_types=1);

namespace App\Listings\Application\UseCases;

use App\Listings\Application\Contracts\DomainEventPublisher;
use App\Listings\Domain\Contracts\ListingRepositoryPort;
use App\Listings\Domain\Events\ListingDeleted;
use App\Listings\Domain\Exceptions\ListingAccessDeniedException;
use App\Listings\Domain\Exceptions\ListingNotFoundException;

/**
 * Cancels (soft-deletes) a listing (SPECS §4.3).
 *
 * Flow:
 *   1. Load the listing; a missing listing is a 404.
 *   2. Owner-only defensive re-check (DESIGN §III); a non-owner is a 403.
 *   3. Idempotency (#15): an already-cancelled listing is a no-op (the caller
 *      still returns 204); ListingDeleted is NOT re-published to avoid
 *      duplicate audit rows.
 *   4. Otherwise soft-delete (cancelled_at=now), persist, and publish
 *      ListingDeleted after commit.
 */
final class CancelListingUseCase
{
    public function __construct(
        private readonly ListingRepositoryPort $repository,
        private readonly DomainEventPublisher $eventPublisher,
    ) {}

    public function execute(int $listingId, int $actorUserId): void
    {
        $listing = $this->repository->findById($listingId);

        if ($listing === null) {
            throw ListingNotFoundException::withId($listingId);
        }

        if ($listing->userId() !== $actorUserId) {
            throw ListingAccessDeniedException::forListing($listingId);
        }

        if ($listing->isCancelled()) {
            return;
        }

        $listing = $listing->cancel();

        $this->repository->cancel($listing);

        $this->eventPublisher->publishAfterCommit(new ListingDeleted($listing));
    }
}
