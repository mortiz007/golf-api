<?php

declare(strict_types=1);

namespace App\Listings\Infrastructure\Repositories;

use App\Listings\Application\Contracts\ListingQueryPort;
use App\Listings\Application\Queries\ListListingsQuery;
use App\Listings\Application\ReadModels\ListingListItem;
use App\Listings\Infrastructure\Eloquent\ListingModel;
use DateTimeImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * Eloquent-backed adapter for ListingQueryPort (S6-06).
 *
 * Read side for the public listing endpoint (SPECS §4.4): applies filters and
 * the frozen visibility/ordering rules (#4, #5), eager-loads the owner and
 * category names to avoid N+1, and maps rows to ListingListItem read models.
 */
final class EloquentListingQueryRepository implements ListingQueryPort
{
    public function search(ListListingsQuery $query): LengthAwarePaginator
    {
        $builder = ListingModel::query()
            ->with(['user:id,name', 'category:id,name']);

        $this->applyFilters($builder, $query);
        $this->applyVisibilityAndOrder($builder, $query);

        return $builder
            ->paginate(perPage: $query->perPage, page: $query->page)
            ->withQueryString()
            ->through(fn (ListingModel $model): ListingListItem => $this->toReadModel($model));
    }

    /**
     * @param  Builder<ListingModel>  $builder
     */
    private function applyFilters(Builder $builder, ListListingsQuery $query): void
    {
        if ($query->minPrice !== null) {
            $builder->where('price', '>=', $query->minPrice);
        }

        if ($query->maxPrice !== null) {
            $builder->where('price', '<=', $query->maxPrice);
        }

        if ($query->categoryId !== null) {
            $builder->where('category_id', $query->categoryId);
        }

        if ($query->condition !== null) {
            $builder->where('condition', $query->condition);
        }

        if ($query->q !== null && $query->q !== '') {
            $term = '%'.$query->q.'%';
            $builder->where(function (Builder $inner) use ($term): void {
                $inner->where('title', 'like', $term)
                    ->orWhere('description', 'like', $term);
            });
        }
    }

    /**
     * Applies the frozen visibility + ordering rules (#4, #5).
     *
     * @param  Builder<ListingModel>  $builder
     */
    private function applyVisibilityAndOrder(Builder $builder, ListListingsQuery $query): void
    {
        if ($query->showAll) {
            $builder->orderByDesc('price');

            return;
        }

        $builder->where('moderation_status', 'approved')
            ->whereNull('cancelled_at')
            ->where(function (Builder $inner): void {
                $inner->whereNull('end_date')
                    ->orWhere('end_date', '>=', today());
            })
            ->orderBy('created_at');
    }

    private function toReadModel(ListingModel $model): ListingListItem
    {
        return new ListingListItem(
            id: (int) $model->id,
            title: $model->title,
            price: (float) $model->price,
            condition: $model->condition,
            description: $model->description,
            createdAt: DateTimeImmutable::createFromInterface($model->created_at),
            userName: (string) ($model->user?->name ?? ''),
            categoryId: (int) $model->category_id,
            categoryName: (string) ($model->category?->name ?? ''),
            aiEnrichment: $model->ai_enrichment,
        );
    }
}
