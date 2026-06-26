<?php

declare(strict_types=1);

namespace App\Listings\Application\UseCases;

use App\Listings\Domain\Contracts\ListingRepositoryPort;
use App\Listings\Domain\Contracts\LlmPort;
use App\Listings\Domain\Llm\ModerationInput;

/**
 * Moderates a listing (SPECS §6 / DESIGN §V.1), invoked by ModerationJob.
 *
 * Flow:
 *   1. Load the listing (skip silently if it no longer exists).
 *   2. Classify its content via LlmPort.
 *   3. Persist moderation_result + the resolved moderation_status.
 *
 * Failures propagate so the job can retry; the pending fallback on definitive
 * failure is handled by the job's failed() callback.
 */
final class ModerateListingUseCase
{
    public function __construct(
        private readonly ListingRepositoryPort $repository,
        private readonly LlmPort $llm,
    ) {}

    public function execute(int $listingId): void
    {
        $listing = $this->repository->findById($listingId);

        if ($listing === null) {
            return;
        }

        $result = $this->llm->moderate(new ModerationInput(
            title: (string) $listing->title(),
            description: (string) $listing->description(),
        ));

        $this->repository->updateModerationResult(
            $listingId,
            $result->toArray(),
            $result->status,
        );
    }
}
