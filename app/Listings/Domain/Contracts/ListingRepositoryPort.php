<?php

declare(strict_types=1);

namespace App\Listings\Domain\Contracts;

use App\Listings\Domain\Entities\Listing;
use App\Listings\Domain\ValueObjects\AiEnrichmentStatus;
use App\Listings\Domain\ValueObjects\ModerationStatus;

/**
 * Outbound port for Listing persistence (Hexagonal architecture).
 *
 * Defined in the Domain layer; the concrete adapter
 * (EloquentListingRepository) lives in Infrastructure (S1-11) and is bound
 * via ServiceProvider (S1-13).
 *
 * The Domain depends only on this contract — never on Eloquent.
 */
interface ListingRepositoryPort
{
    /**
     * Persists a new (or updated) Listing and returns the entity with its
     * persisted identity assigned (id populated by the storage engine).
     *
     * @param  Listing  $listing  Entity to persist (id may be null for inserts).
     * @return Listing The same listing with a non-null id.
     */
    public function save(Listing $listing): Listing;

    /**
     * Persists changes to an existing Listing (PATCH, SPECS §4.2).
     *
     * Updates only the editable columns and the moderation/enrichment statuses,
     * preserving moderation_result/ai_enrichment payloads.
     */
    public function update(Listing $listing): Listing;

    /**
     * Loads a Listing by its identity, or null if it does not exist.
     */
    public function findById(int $id): ?Listing;

    /**
     * Persists the moderation outcome, touching only the moderation columns so
     * concurrent enrichment writes are never clobbered (jobs run in parallel).
     *
     * @param  array<string, mixed>  $result  JSON payload for moderation_result.
     */
    public function updateModerationResult(int $listingId, array $result, ModerationStatus $status): void;

    /**
     * Persists the enrichment outcome, touching only the enrichment columns so
     * concurrent moderation writes are never clobbered (jobs run in parallel).
     *
     * @param  array<string, mixed>|null  $enrichment  JSON payload for ai_enrichment.
     */
    public function updateEnrichment(int $listingId, ?array $enrichment, AiEnrichmentStatus $status): void;
}
