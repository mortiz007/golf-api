<?php

declare(strict_types=1);

namespace App\Listings\Infrastructure\Dispatchers;

use App\Listings\Application\Contracts\ListingProcessingDispatcher;
use App\Listings\Infrastructure\Jobs\EnrichmentJob;
use App\Listings\Infrastructure\Jobs\ModerationJob;

/**
 * Laravel adapter for the ListingProcessingDispatcher port (S1-08).
 *
 * Enqueues the two independent LLM jobs onto the `database` queue. This is the
 * only place that references the concrete Job classes — Application never does.
 */
final class LaravelListingProcessingDispatcher implements ListingProcessingDispatcher
{
    public function dispatchModeration(int $listingId): void
    {
        ModerationJob::dispatch($listingId);
    }

    public function dispatchEnrichment(int $listingId): void
    {
        EnrichmentJob::dispatch($listingId);
    }
}
