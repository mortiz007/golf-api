<?php

declare(strict_types=1);

namespace App\Listings\Infrastructure\Repositories;

use App\Listings\Domain\Contracts\ListingRepositoryPort;
use App\Listings\Domain\Entities\Listing;
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
}
