<?php

declare(strict_types=1);

use App\Listings\Application\Contracts\DomainEventPublisher;
use App\Listings\Application\UseCases\CancelListingUseCase;
use App\Listings\Domain\Contracts\ListingRepositoryPort;
use App\Listings\Domain\Entities\Listing;
use App\Listings\Domain\Events\ListingDeleted;
use App\Listings\Domain\Exceptions\ListingAccessDeniedException;
use App\Listings\Domain\Exceptions\ListingNotFoundException;
use App\Listings\Domain\ValueObjects\AiEnrichmentStatus;
use App\Listings\Domain\ValueObjects\Description;
use App\Listings\Domain\ValueObjects\ListingCondition;
use App\Listings\Domain\ValueObjects\ModerationStatus;
use App\Listings\Domain\ValueObjects\Price;
use App\Listings\Domain\ValueObjects\Title;

function cancellableListing(bool $cancelled = false): Listing
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

function fakeCancelRepository(?Listing $listing): ListingRepositoryPort
{
    return new class($listing) implements ListingRepositoryPort
    {
        public ?Listing $cancelled = null;

        public function __construct(private readonly ?Listing $listing) {}

        public function save(Listing $listing): Listing
        {
            return $listing;
        }

        public function update(Listing $listing): Listing
        {
            return $listing;
        }

        public function findById(int $id): ?Listing
        {
            return $this->listing;
        }

        public function cancel(Listing $listing): void
        {
            $this->cancelled = $listing;
        }

        public function updateModerationResult(int $listingId, array $result, ModerationStatus $status): void {}

        public function updateEnrichment(int $listingId, ?array $enrichment, AiEnrichmentStatus $status): void {}
    };
}

function cancelSpyPublisher(): DomainEventPublisher
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

it('soft-deletes the listing and publishes ListingDeleted', function () {
    $repository = fakeCancelRepository(cancellableListing());
    $publisher = cancelSpyPublisher();

    (new CancelListingUseCase($repository, $publisher))->execute(42, 7);

    expect($repository->cancelled)->not->toBeNull()
        ->and($repository->cancelled->isCancelled())->toBeTrue()
        ->and($publisher->event)->toBeInstanceOf(ListingDeleted::class);
});

it('is idempotent and does not re-publish when already cancelled', function () {
    $repository = fakeCancelRepository(cancellableListing(cancelled: true));
    $publisher = cancelSpyPublisher();

    (new CancelListingUseCase($repository, $publisher))->execute(42, 7);

    expect($repository->cancelled)->toBeNull()
        ->and($publisher->event)->toBeNull();
});

it('throws not found when the listing does not exist', function () {
    (new CancelListingUseCase(fakeCancelRepository(null), cancelSpyPublisher()))->execute(42, 7);
})->throws(ListingNotFoundException::class);

it('throws access denied when the actor is not the owner', function () {
    (new CancelListingUseCase(fakeCancelRepository(cancellableListing()), cancelSpyPublisher()))->execute(42, 999);
})->throws(ListingAccessDeniedException::class);

it('enforces ownership even on an already-cancelled listing', function () {
    (new CancelListingUseCase(fakeCancelRepository(cancellableListing(cancelled: true)), cancelSpyPublisher()))->execute(42, 999);
})->throws(ListingAccessDeniedException::class);
