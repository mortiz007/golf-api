<?php

declare(strict_types=1);

namespace App\Listings\Application\UseCases;

use App\Listings\Application\Commands\CreateListingCommand;
use App\Listings\Application\Contracts\DomainEventPublisher;
use App\Listings\Application\Contracts\ListingProcessingDispatcher;
use App\Listings\Domain\Contracts\ListingRepositoryPort;
use App\Listings\Domain\Entities\Listing;
use App\Listings\Domain\Events\ListingCreated;
use App\Listings\Domain\ValueObjects\Description;
use App\Listings\Domain\ValueObjects\EndDate;
use App\Listings\Domain\ValueObjects\ListingCondition;
use App\Listings\Domain\ValueObjects\Price;
use App\Listings\Domain\ValueObjects\Title;

/**
 * Creates a listing (SPECS §4.1).
 *
 * Flow:
 *   1. Build the domain entity from the command (VOs re-validate as a safety net).
 *   2. Persist via the repository port (returns the entity with its id).
 *   3. Publish ListingCreated AFTER the transaction commits.
 *   4. Enqueue the two independent LLM jobs (moderation + enrichment).
 *
 * No SQL, no HTTP, no Eloquent, no Job classes — only domain + outbound ports.
 */
final class CreateListingUseCase
{
    public function __construct(
        private readonly ListingRepositoryPort $repository,
        private readonly DomainEventPublisher $eventPublisher,
        private readonly ListingProcessingDispatcher $processing,
    ) {}

    public function execute(CreateListingCommand $command): Listing
    {
        $listing = Listing::create(
            userId: $command->actorUserId,
            categoryId: $command->categoryId,
            title: new Title($command->title),
            price: new Price($command->price),
            condition: new ListingCondition($command->condition),
            description: new Description($command->description),
            endDate: $command->endDate !== null ? new EndDate($command->endDate) : null,
        );

        $listing = $this->repository->save($listing);

        $this->eventPublisher->publishAfterCommit(new ListingCreated($listing));

        $listingId = $listing->id();
        $this->processing->dispatchModeration($listingId);
        $this->processing->dispatchEnrichment($listingId);

        return $listing;
    }
}
