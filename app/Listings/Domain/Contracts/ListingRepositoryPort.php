<?php

declare(strict_types=1);

namespace App\Listings\Domain\Contracts;

use App\Listings\Domain\Entities\Listing;

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
}
