<?php

declare(strict_types=1);

use App\Listings\Application\Commands\UpdateListingCommand;
use App\Listings\Application\Contracts\DomainEventPublisher;
use App\Listings\Application\Contracts\ListingProcessingDispatcher;
use App\Listings\Application\UseCases\UpdateListingUseCase;
use App\Listings\Domain\Contracts\ListingRepositoryPort;
use App\Listings\Domain\Entities\Listing;
use App\Listings\Domain\Events\ListingUpdated;
use App\Listings\Domain\Exceptions\ListingAccessDeniedException;
use App\Listings\Domain\Exceptions\ListingNotFoundException;
use App\Listings\Domain\ValueObjects\AiEnrichmentStatus;
use App\Listings\Domain\ValueObjects\Description;
use App\Listings\Domain\ValueObjects\ListingCondition;
use App\Listings\Domain\ValueObjects\ModerationStatus;
use App\Listings\Domain\ValueObjects\Price;
use App\Listings\Domain\ValueObjects\Title;

function existingListing(bool $cancelled = false): Listing
{
    return Listing::fromState(
        id: 42,
        userId: 7,
        categoryId: 1,
        title: new Title('Driver Pro'),
        price: new Price(199.99),
        condition: new ListingCondition('Used'),
        description: new Description('Great club for sale here'),
        endDate: null,
        moderationStatus: ModerationStatus::APPROVED,
        aiEnrichmentStatus: AiEnrichmentStatus::SUCCEEDED,
        createdAt: new DateTimeImmutable('2026-06-01T00:00:00Z'),
        cancelledAt: $cancelled ? new DateTimeImmutable('2026-06-02T00:00:00Z') : null,
    );
}

function fakeUpdateRepository(?Listing $listing): ListingRepositoryPort
{
    return new class($listing) implements ListingRepositoryPort
    {
        public ?Listing $updated = null;

        public function __construct(private readonly ?Listing $listing) {}

        public function save(Listing $listing): Listing
        {
            return $listing;
        }

        public function update(Listing $listing): Listing
        {
            $this->updated = $listing;

            return $listing;
        }

        public function findById(int $id): ?Listing
        {
            return $this->listing;
        }

        public function updateModerationResult(int $listingId, array $result, ModerationStatus $status): void {}

        public function updateEnrichment(int $listingId, ?array $enrichment, AiEnrichmentStatus $status): void {}
    };
}

function spyDispatcher(): ListingProcessingDispatcher
{
    return new class implements ListingProcessingDispatcher
    {
        public bool $moderationDispatched = false;

        public bool $enrichmentDispatched = false;

        public function dispatchModeration(int $listingId): void
        {
            $this->moderationDispatched = true;
        }

        public function dispatchEnrichment(int $listingId): void
        {
            $this->enrichmentDispatched = true;
        }
    };
}

function spyPublisher(): DomainEventPublisher
{
    return new class implements DomainEventPublisher
    {
        public ?object $event = null;

        public function publishAfterCommit(object $event): void
        {
            $this->event = $event;
        }
    };
}

function command(array $changes, int $actorUserId = 7, int $listingId = 42): UpdateListingCommand
{
    return UpdateListingCommand::fromArray($actorUserId, $listingId, $changes);
}

it('resets moderation and re-queues it when the title changes', function () {
    $repository = fakeUpdateRepository(existingListing());
    $dispatcher = spyDispatcher();
    $publisher = spyPublisher();

    $result = (new UpdateListingUseCase($repository, $publisher, $dispatcher))
        ->execute(command(['title' => 'Brand New Driver']));

    expect($result->moderationStatus())->toBe(ModerationStatus::PENDING)
        ->and($result->aiEnrichmentStatus())->toBe(AiEnrichmentStatus::SUCCEEDED)
        ->and($dispatcher->moderationDispatched)->toBeTrue()
        ->and($dispatcher->enrichmentDispatched)->toBeFalse()
        ->and($publisher->event)->toBeInstanceOf(ListingUpdated::class);
});

it('resets enrichment and re-queues it when the price changes', function () {
    $repository = fakeUpdateRepository(existingListing());
    $dispatcher = spyDispatcher();

    $result = (new UpdateListingUseCase($repository, spyPublisher(), $dispatcher))
        ->execute(command(['price' => 250.00]));

    expect($result->aiEnrichmentStatus())->toBe(AiEnrichmentStatus::PENDING)
        ->and($result->moderationStatus())->toBe(ModerationStatus::APPROVED)
        ->and($dispatcher->enrichmentDispatched)->toBeTrue()
        ->and($dispatcher->moderationDispatched)->toBeFalse();
});

it('resets enrichment when the condition changes', function () {
    $repository = fakeUpdateRepository(existingListing());
    $dispatcher = spyDispatcher();

    (new UpdateListingUseCase($repository, spyPublisher(), $dispatcher))
        ->execute(command(['condition' => 'New']));

    expect($dispatcher->enrichmentDispatched)->toBeTrue();
});

it('does not re-queue when only end_date or category_id change', function () {
    $repository = fakeUpdateRepository(existingListing());
    $dispatcher = spyDispatcher();

    $result = (new UpdateListingUseCase($repository, spyPublisher(), $dispatcher))
        ->execute(command(['end_date' => '2999-01-01', 'category_id' => 2]));

    expect($dispatcher->moderationDispatched)->toBeFalse()
        ->and($dispatcher->enrichmentDispatched)->toBeFalse()
        ->and($result->categoryId())->toBe(2)
        ->and($result->endDate()?->toString())->toBe('2999-01-01')
        ->and($result->moderationStatus())->toBe(ModerationStatus::APPROVED);
});

it('does not re-queue when the submitted value equals the current one', function () {
    $repository = fakeUpdateRepository(existingListing());
    $dispatcher = spyDispatcher();

    (new UpdateListingUseCase($repository, spyPublisher(), $dispatcher))
        ->execute(command(['title' => 'Driver Pro', 'price' => 199.99]));

    expect($dispatcher->moderationDispatched)->toBeFalse()
        ->and($dispatcher->enrichmentDispatched)->toBeFalse();
});

it('throws not found when the listing does not exist', function () {
    $useCase = new UpdateListingUseCase(fakeUpdateRepository(null), spyPublisher(), spyDispatcher());

    $useCase->execute(command(['title' => 'New Title']));
})->throws(ListingNotFoundException::class);

it('throws not found when the listing is cancelled', function () {
    $useCase = new UpdateListingUseCase(fakeUpdateRepository(existingListing(cancelled: true)), spyPublisher(), spyDispatcher());

    $useCase->execute(command(['title' => 'New Title']));
})->throws(ListingNotFoundException::class);

it('throws access denied when the actor is not the owner', function () {
    $useCase = new UpdateListingUseCase(fakeUpdateRepository(existingListing()), spyPublisher(), spyDispatcher());

    $useCase->execute(command(['title' => 'New Title'], actorUserId: 999));
})->throws(ListingAccessDeniedException::class);
