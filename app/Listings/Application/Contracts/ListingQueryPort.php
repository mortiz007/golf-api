<?php

declare(strict_types=1);

namespace App\Listings\Application\Contracts;

use App\Listings\Application\Queries\ListListingsQuery;
use App\Listings\Application\ReadModels\ListingListItem;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Outbound read port for the public listing query (GET /api/listings, SPECS §4.4).
 *
 * Returns the generic Illuminate paginator contract (not Eloquent) whose items
 * are ListingListItem read models, keeping Application free of persistence
 * details (consistent with ADR-010's dependency rule).
 */
interface ListingQueryPort
{
    /**
     * @return LengthAwarePaginator<int, ListingListItem>
     */
    public function search(ListListingsQuery $query): LengthAwarePaginator;
}
