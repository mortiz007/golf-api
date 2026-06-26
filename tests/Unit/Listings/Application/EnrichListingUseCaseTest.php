<?php

declare(strict_types=1);

use App\Listings\Application\UseCases\EnrichListingUseCase;
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
 * In-memory repository fake capturing enrichment writes (no DB).
 */
function fakeEnrichmentRepository(?Listing $listing): ListingRepositoryPort
{
    return new class($listing) implements ListingRepositoryPort
    {
        /** @var array{listingId: int, enrichment: array<string, mixed>|null, status: AiEnrichmentStatus}|null */
        public ?array $enrichmentWrite = null;

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

        public function updateModerationResult(int $listingId, array $result, ModerationStatus $status): void {}

        public function updateEnrichment(int $listingId, ?array $enrichment, AiEnrichmentStatus $status): void
        {
            $this->enrichmentWrite = ['listingId' => $listingId, 'enrichment' => $enrichment, 'status' => $status];
        }
    };
}

/**
 * LlmPort fake returning a fixed estimated market value.
 */
function fakeEnrichmentLlm(float $estimatedValue): LlmPort
{
    return new class($estimatedValue) implements LlmPort
    {
        public ?EnrichmentInput $received = null;

        public function __construct(private readonly float $estimatedValue) {}

        public function moderate(ModerationInput $input): ModerationResult
        {
            throw new RuntimeException('moderate should not be called by the enrichment use case');
        }

        public function enrich(EnrichmentInput $input): EnrichmentResult
        {
            $this->received = $input;

            return new EnrichmentResult(
                modelEvaluation: ['summary' => 'stub', 'features' => [], 'confidence' => 0.5],
                estimatedMarketValue: [
                    'value' => $this->estimatedValue,
                    'currency' => 'USD',
                    'confidence_interval' => [],
                    'confidence' => 0.5,
                    'basis' => 'stub',
                ],
                model: 'fake',
                generatedAt: '2026-06-26T00:00:00Z',
            );
        }
    };
}

function enrichmentListing(): Listing
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

it('persists a successful enrichment result', function () {
    $repository = fakeEnrichmentRepository(enrichmentListing());
    $llm = fakeEnrichmentLlm(129.99);

    (new EnrichListingUseCase($repository, $llm))->execute(42);

    expect($repository->enrichmentWrite['listingId'])->toBe(42)
        ->and($repository->enrichmentWrite['status'])->toBe(AiEnrichmentStatus::SUCCEEDED)
        ->and($repository->enrichmentWrite['enrichment']['estimated_market_value']['value'])->toBe(129.99)
        ->and($repository->enrichmentWrite['enrichment']['estimated_market_value']['currency'])->toBe('USD');
});

it('feeds the listing attributes to the LLM', function () {
    $repository = fakeEnrichmentRepository(enrichmentListing());
    $llm = fakeEnrichmentLlm(129.99);

    (new EnrichListingUseCase($repository, $llm))->execute(42);

    expect($llm->received->title)->toBe('Driver Pro')
        ->and($llm->received->price)->toBe(199.99)
        ->and($llm->received->condition)->toBe('Used');
});

it('does nothing when the listing no longer exists', function () {
    $repository = fakeEnrichmentRepository(null);
    $llm = fakeEnrichmentLlm(129.99);

    (new EnrichListingUseCase($repository, $llm))->execute(42);

    expect($repository->enrichmentWrite)->toBeNull();
});
