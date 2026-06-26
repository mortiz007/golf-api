<?php

declare(strict_types=1);

namespace App\Listings\Application\Contracts;

/**
 * Outbound port to enqueue the asynchronous LLM processing jobs.
 *
 * Two independent, parallel tasks (DESIGN §V): moderation and enrichment.
 * The Infrastructure adapter (S1-12/S1-13) pushes ModerationJob / EnrichmentJob
 * onto the `database` queue. Application never references the Job classes.
 */
interface ListingProcessingDispatcher
{
    public function dispatchModeration(int $listingId): void;

    public function dispatchEnrichment(int $listingId): void;
}
