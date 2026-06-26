<?php

declare(strict_types=1);

namespace App\Listings\Infrastructure\Repositories;

use App\Listings\Domain\Contracts\ListingRepositoryPort;
use App\Listings\Domain\Entities\Listing;
use App\Listings\Domain\ValueObjects\AiEnrichmentStatus;
use App\Listings\Domain\ValueObjects\ModerationStatus;
use App\Listings\Infrastructure\Eloquent\ListingModel;
use App\Listings\Infrastructure\Mappers\ListingMapper;

/**
 * Eloquent-backed adapter for ListingRepositoryPort (S1-05).
 *
 * The only Infrastructure component allowed to touch the database for the
 * Listings bounded context. Bound to the port in the ServiceProvider (S1-13).
 */
final class EloquentListingRepository implements ListingRepositoryPort
{
    public function __construct(
        private readonly ListingMapper $mapper,
    ) {}

    public function save(Listing $listing): Listing
    {
        $model = ListingModel::create(
            $this->mapper->toAttributes($listing)
        );

        return $listing->withId((int) $model->id);
    }

    public function findById(int $id): ?Listing
    {
        $model = ListingModel::find($id);

        return $model !== null ? $this->mapper->toDomain($model) : null;
    }

    public function updateModerationResult(int $listingId, array $result, ModerationStatus $status): void
    {
        $model = ListingModel::find($listingId);

        if ($model === null) {
            return;
        }

        // Only moderation columns are touched (save() persists dirty attributes
        // only), so a concurrent enrichment write is never clobbered.
        $model->moderation_result = $result;
        $model->moderation_status = $status->value;
        $model->save();
    }

    public function updateEnrichment(int $listingId, ?array $enrichment, AiEnrichmentStatus $status): void
    {
        $model = ListingModel::find($listingId);

        if ($model === null) {
            return;
        }

        // Only enrichment columns are touched (save() persists dirty attributes
        // only), so a concurrent moderation write is never clobbered.
        $model->ai_enrichment = $enrichment;
        $model->ai_enrichment_status = $status->value;
        $model->save();
    }
}
