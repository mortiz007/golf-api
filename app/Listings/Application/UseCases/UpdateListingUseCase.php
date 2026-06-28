<?php

declare(strict_types=1);

namespace App\Listings\Application\UseCases;

use App\Listings\Application\Commands\UpdateListingCommand;
use App\Listings\Application\Contracts\DomainEventPublisher;
use App\Listings\Application\Contracts\ListingProcessingDispatcher;
use App\Listings\Domain\Contracts\ListingRepositoryPort;
use App\Listings\Domain\Entities\Listing;
use App\Listings\Domain\Events\ListingUpdated;
use App\Listings\Domain\Exceptions\ListingAccessDeniedException;
use App\Listings\Domain\Exceptions\ListingNotFoundException;
use App\Listings\Domain\ValueObjects\AiEnrichmentStatus;
use App\Listings\Domain\ValueObjects\Description;
use App\Listings\Domain\ValueObjects\EndDate;
use App\Listings\Domain\ValueObjects\ListingCondition;
use App\Listings\Domain\ValueObjects\ModerationStatus;
use App\Listings\Domain\ValueObjects\Price;
use App\Listings\Domain\ValueObjects\Title;

/**
 * Partially updates a listing (SPECS §4.2).
 *
 * Flow:
 *   1. Load the listing; a missing or cancelled listing is a 404.
 *   2. Owner-only defensive re-check (DESIGN §III); a non-owner is a 403.
 *   3. Apply only the present fields (VOs re-validate as a safety net).
 *   4. Re-evaluation triggers (SPECS §4.2):
 *        - title/description changed  -> moderation_status=pending + re-queue moderation
 *        - price/condition changed    -> ai_enrichment_status=pending + re-queue enrichment
 *   5. Persist; publish ListingUpdated after commit ONLY when at least one
 *      user-submitted field actually changed; re-queue jobs as needed.
 */
final class UpdateListingUseCase
{
    public function __construct(
        private readonly ListingRepositoryPort $repository,
        private readonly DomainEventPublisher $eventPublisher,
        private readonly ListingProcessingDispatcher $processing,
    ) {}

    public function execute(UpdateListingCommand $command): Listing
    {
        $listing = $this->repository->findById($command->listingId);

        if ($listing === null || $listing->isCancelled()) {
            throw ListingNotFoundException::withId($command->listingId);
        }

        if ($listing->userId() !== $command->actorUserId) {
            throw ListingAccessDeniedException::forListing($command->listingId);
        }

        $needsModeration = false;
        $needsEnrichment = false;

        /** @var array<string, array{old: mixed, new: mixed}> $changes */
        $changes = [];

        if ($command->has('title')) {
            $title = new Title((string) $command->changes['title']);
            if (! $title->equals($listing->title())) {
                $changes['title'] = ['old' => (string) $listing->title(), 'new' => (string) $title];
                $listing = $listing->withTitle($title);
                $needsModeration = true;
            }
        }

        if ($command->has('description')) {
            $description = new Description((string) $command->changes['description']);
            if (! $description->equals($listing->description())) {
                $changes['description'] = ['old' => (string) $listing->description(), 'new' => (string) $description];
                $listing = $listing->withDescription($description);
                $needsModeration = true;
            }
        }

        if ($command->has('price')) {
            $price = new Price((float) $command->changes['price']);
            if (! $price->equals($listing->price())) {
                $changes['price'] = ['old' => $listing->price()->value(), 'new' => $price->value()];
                $listing = $listing->withPrice($price);
                $needsEnrichment = true;
            }
        }

        if ($command->has('condition')) {
            $condition = new ListingCondition((string) $command->changes['condition']);
            if (! $condition->equals($listing->condition())) {
                $changes['condition'] = ['old' => (string) $listing->condition(), 'new' => (string) $condition];
                $listing = $listing->withCondition($condition);
                $needsEnrichment = true;
            }
        }

        if ($command->has('end_date')) {
            $endDate = $command->changes['end_date'] !== null
                ? new EndDate((string) $command->changes['end_date'])
                : null;
            $currentEndDate = $listing->endDate();
            $endDateChanged = ($endDate?->toString()) !== ($currentEndDate?->toString());
            if ($endDateChanged) {
                $changes['end_date'] = ['old' => $currentEndDate?->toString(), 'new' => $endDate?->toString()];
                $listing = $listing->withEndDate($endDate);
            }
        }

        if ($command->has('category_id')) {
            $categoryId = (int) $command->changes['category_id'];
            if ($categoryId !== $listing->categoryId()) {
                $changes['category_id'] = ['old' => $listing->categoryId(), 'new' => $categoryId];
                $listing = $listing->withCategoryId($categoryId);
            }
        }

        if ($needsModeration) {
            $listing = $listing->withModerationStatus(ModerationStatus::PENDING);
        }

        if ($needsEnrichment) {
            $listing = $listing->withAiEnrichmentStatus(AiEnrichmentStatus::PENDING);
        }

        $listing = $this->repository->update($listing);

        if ($changes !== []) {
            $this->eventPublisher->publishAfterCommit(new ListingUpdated($listing, $changes));
        }

        if ($needsModeration) {
            $this->processing->dispatchModeration($command->listingId);
        }

        if ($needsEnrichment) {
            $this->processing->dispatchEnrichment($command->listingId);
        }

        return $listing;
    }
}
