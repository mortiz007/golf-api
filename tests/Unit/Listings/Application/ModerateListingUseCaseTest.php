<?php

declare(strict_types=1);

use App\Listings\Application\UseCases\ModerateListingUseCase;
use App\Listings\Domain\Contracts\ListingRepositoryPort;
use App\Listings\Domain\Contracts\LlmPort;
use App\Listings\Domain\Entities\Listing;
use App\Listings\Domain\Llm\EnrichmentInput;
use App\Listings\Domain\Llm\EnrichmentResult;
use App\Listings\Domain\Llm\ModerationInput;
use App\Listings\Domain\Llm\ModerationResult;
use App\Listings\Domain\ValueObjects\AiEnrichmentStatus;
use App\Listings\Domain\ValueObjects\Description;
use App\Listings\Domain\ValueObjects\ListingCondition;
use App\Listings\Domain\ValueObjects\ModerationStatus;
use App\Listings\Domain\ValueObjects\Price;
use App\Listings\Domain\ValueObjects\Title;

/**
 * In-memory repository fake capturing moderation writes (no DB).
 */
function fakeModerationRepository(?Listing $listing): ListingRepositoryPort
{
    return new class($listing) implements ListingRepositoryPort
    {
        /** @var array{listingId: int, result: array<string, mixed>, status: ModerationStatus}|null */
        public ?array $moderationWrite = null;

        public bool $enrichmentCalled = false;

        public function __construct(private readonly ?Listing $listing) {}

        public function save(Listing $listing): Listing
        {
            return $listing;
        }

        public function findById(int $id): ?Listing
        {
            return $this->listing;
        }

        public function updateModerationResult(int $listingId, array $result, ModerationStatus $status): void
        {
            $this->moderationWrite = ['listingId' => $listingId, 'result' => $result, 'status' => $status];
        }

        public function updateEnrichment(int $listingId, ?array $enrichment, AiEnrichmentStatus $status): void
        {
            $this->enrichmentCalled = true;
        }
    };
}

/**
 * LlmPort fake returning a predetermined moderation status.
 */
function fakeModerationLlm(ModerationStatus $status): LlmPort
{
    return new class($status) implements LlmPort
    {
        public ?ModerationInput $received = null;

        public function __construct(private readonly ModerationStatus $status) {}

        public function moderate(ModerationInput $input): ModerationResult
        {
            $this->received = $input;

            return new ModerationResult(
                status: $this->status,
                labels: [],
                scores: [],
                explanation: 'stub',
                model: 'fake',
                timestamp: '2026-06-26T00:00:00Z',
            );
        }

        public function enrich(EnrichmentInput $input): EnrichmentResult
        {
            throw new RuntimeException('enrich should not be called by the moderation use case');
        }
    };
}

function moderationListing(): Listing
{
    return Listing::create(
        userId: 1,
        categoryId: 1,
        title: new Title('Driver Pro'),
        price: new Price(199.99),
        condition: new ListingCondition('Used'),
        description: new Description('A great club for sale'),
    )->withId(42);
}

it('persists an approved moderation result', function () {
    $repository = fakeModerationRepository(moderationListing());
    $llm = fakeModerationLlm(ModerationStatus::APPROVED);

    (new ModerateListingUseCase($repository, $llm))->execute(42);

    expect($repository->moderationWrite['listingId'])->toBe(42)
        ->and($repository->moderationWrite['status'])->toBe(ModerationStatus::APPROVED)
        ->and($repository->moderationWrite['result']['status'])->toBe('approved')
        ->and($repository->enrichmentCalled)->toBeFalse();
});

it('persists a rejected moderation result', function () {
    $repository = fakeModerationRepository(moderationListing());
    $llm = fakeModerationLlm(ModerationStatus::REJECTED);

    (new ModerateListingUseCase($repository, $llm))->execute(42);

    expect($repository->moderationWrite['status'])->toBe(ModerationStatus::REJECTED)
        ->and($repository->moderationWrite['result']['status'])->toBe('rejected');
});

it('feeds the listing content to the LLM', function () {
    $repository = fakeModerationRepository(moderationListing());
    $llm = fakeModerationLlm(ModerationStatus::APPROVED);

    (new ModerateListingUseCase($repository, $llm))->execute(42);

    expect($llm->received->title)->toBe('Driver Pro')
        ->and($llm->received->description)->toBe('A great club for sale');
});

it('does nothing when the listing no longer exists', function () {
    $repository = fakeModerationRepository(null);
    $llm = fakeModerationLlm(ModerationStatus::APPROVED);

    (new ModerateListingUseCase($repository, $llm))->execute(42);

    expect($repository->moderationWrite)->toBeNull();
});
