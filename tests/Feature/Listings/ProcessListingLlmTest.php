<?php

declare(strict_types=1);

use App\Listings\Domain\Contracts\LlmPort;
use App\Listings\Domain\Llm\EnrichmentInput;
use App\Listings\Domain\Llm\EnrichmentResult;
use App\Listings\Domain\Llm\ModerationInput;
use App\Listings\Domain\Llm\ModerationResult;
use App\Listings\Infrastructure\Eloquent\ListingModel;
use App\Listings\Infrastructure\Jobs\EnrichmentJob;
use App\Listings\Infrastructure\Jobs\ModerationJob;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * Persists a pending listing row (with its FK dependencies) and returns its id.
 */
function seedPendingListing(string $description = 'A great club for sale'): int
{
    $userId = User::factory()->create()->id;
    $categoryId = (int) DB::table('categories')->insertGetId([
        'name' => 'Drivers',
        'created_at' => now(),
    ]);

    return (int) ListingModel::create([
        'user_id' => $userId,
        'category_id' => $categoryId,
        'title' => 'Driver Pro',
        'price' => 199.99,
        'condition' => 'Used',
        'description' => $description,
        'moderation_status' => 'pending',
        'ai_enrichment_status' => 'pending',
    ])->id;
}

it('approves a clean listing and writes the moderation result', function () {
    $id = seedPendingListing();

    ModerationJob::dispatchSync($id);

    $listing = ListingModel::find($id);
    expect($listing->moderation_status)->toBe('approved')
        ->and($listing->moderation_result['status'])->toBe('approved')
        ->and($listing->moderation_result['model'])->toBe('mock-llm-v1')
        ->and($listing->ai_enrichment)->toBeNull();
});

it('rejects a listing whose content is flagged', function () {
    $id = seedPendingListing('This is a total scam, do not buy');

    ModerationJob::dispatchSync($id);

    expect(ListingModel::find($id)->moderation_status)->toBe('rejected');
});

it('enriches a listing and writes the estimated market value', function () {
    $id = seedPendingListing();

    EnrichmentJob::dispatchSync($id);

    $listing = ListingModel::find($id);
    expect($listing->ai_enrichment_status)->toBe('succeeded')
        ->and($listing->ai_enrichment['estimated_market_value']['value'])->toBe(129.99)
        ->and($listing->ai_enrichment['estimated_market_value']['currency'])->toBe('USD')
        ->and($listing->moderation_status)->toBe('pending');
});

it('keeps moderation pending and records the error on definitive failure', function () {
    $id = seedPendingListing();

    (new ModerationJob($id))->failed(new RuntimeException('llm timeout'));

    $listing = ListingModel::find($id);
    expect($listing->moderation_status)->toBe('pending')
        ->and($listing->moderation_result['error'])->toBe('llm timeout');
});

it('marks enrichment failed and records the error on definitive failure', function () {
    $id = seedPendingListing();

    (new EnrichmentJob($id))->failed(new RuntimeException('llm timeout'));

    $listing = ListingModel::find($id);
    expect($listing->ai_enrichment_status)->toBe('failed')
        ->and($listing->ai_enrichment['error'])->toBe('llm timeout');
});

it('lets the configured LlmPort adapter be swapped', function () {
    $id = seedPendingListing();

    $this->app->bind(LlmPort::class, fn () => new class implements LlmPort
    {
        public function moderate(ModerationInput $input): ModerationResult
        {
            throw new RuntimeException('not used');
        }

        public function enrich(EnrichmentInput $input): EnrichmentResult
        {
            return new EnrichmentResult(
                modelEvaluation: ['summary' => 'custom', 'features' => [], 'confidence' => 1.0],
                estimatedMarketValue: [
                    'value' => 1.0,
                    'currency' => 'USD',
                    'confidence_interval' => [],
                    'confidence' => 1.0,
                    'basis' => 'custom',
                ],
                model: 'custom-adapter',
                generatedAt: '2026-06-26T00:00:00Z',
            );
        }
    });

    EnrichmentJob::dispatchSync($id);

    expect(ListingModel::find($id)->ai_enrichment['model'])->toBe('custom-adapter');
});
