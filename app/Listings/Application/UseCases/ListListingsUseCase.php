<?php

declare(strict_types=1);

namespace App\Listings\Application\UseCases;

use App\Listings\Application\Contracts\ListingQueryPort;
use App\Listings\Application\Queries\ListListingsQuery;
use App\Listings\Application\ReadModels\ListingListItem;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Lists public listings with filtering, visibility and pagination
 * (GET /api/listings, SPECS §4.4).
 *
 * Pure read use case: delegates to the query port and returns a paginator of
 * ListingListItem read models. Visibility/ordering rules (#4, #5) live in the
 * adapter; the use case only carries the criteria.
 */
final class ListListingsUseCase
{
    public function __construct(
        private readonly ListingQueryPort $listings,
    ) {}

    /**
     * @return LengthAwarePaginator<int, ListingListItem>
     */
    public function execute(ListListingsQuery $query): LengthAwarePaginator
    {
        return $this->listings->search($query);
    }
}
