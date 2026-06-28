<?php

declare(strict_types=1);

namespace App\Listings\Application\UseCases;

use App\Listings\Domain\Contracts\ListingRepositoryPort;
use App\Listings\Domain\Contracts\LlmPort;
use App\Listings\Domain\Llm\EnrichmentInput;
use App\Listings\Domain\ValueObjects\AiEnrichmentStatus;

/**
 * Enriches a listing (SPECS §6 / DESIGN §V.1), invoked by EnrichmentJob.
 *
 * Independent of moderation (DESIGN §V): does not read the moderation result.
 *
 * Flow:
 *   1. Load the listing (skip if it no longer exists).
 *   2. Generate the evaluation + estimated market value via LlmPort.
 *   3. Persist ai_enrichment + ai_enrichment_status = succeeded.
 *
 * Failures propagate so the job can retry; the failed fallback on definitive
 * failure is handled by the job's failed() callback.
 */
final class EnrichListingUseCase
{
    public function __construct(
        private readonly ListingRepositoryPort $repository,
        private readonly LlmPort $llm,
    ) {}

    /**
     * @return bool true if the listing was processed, false if it no longer
     *              exists and the work was skipped.
     */
    public function execute(int $listingId): bool
    {
        $listing = $this->repository->findById($listingId);

        if ($listing === null) {
            return false;
        }

        $result = $this->llm->enrich(new EnrichmentInput(
            title: (string) $listing->title(),
            description: (string) $listing->description(),
            price: $listing->price()->value(),
            condition: (string) $listing->condition(),
        ));

        $this->repository->updateEnrichment(
            $listingId,
            $result->toArray(),
            AiEnrichmentStatus::SUCCEEDED,
        );

        return true;
    }
}
