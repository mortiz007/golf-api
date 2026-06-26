<?php

declare(strict_types=1);

namespace App\Listings\Application\Queries;

/**
 * Immutable filter/pagination criteria for the public listing query
 * (GET /api/listings, SPECS §4.4).
 *
 * Built from the validated HTTP query string; the use case and query port
 * depend only on this DTO, never on the HTTP request.
 */
final class ListListingsQuery
{
    public const DEFAULT_PER_PAGE = 20;

    public function __construct(
        public readonly ?float $minPrice,
        public readonly ?float $maxPrice,
        public readonly ?int $categoryId,
        public readonly ?string $condition,
        public readonly ?string $q,
        public readonly bool $showAll,
        public readonly int $page,
        public readonly int $perPage,
    ) {}

    /**
     * Builds the query DTO from already-validated input, applying defaults
     * (show_all=false, page=1, per_page=20).
     *
     * @param  array<string, mixed>  $validated
     */
    public static function fromValidated(array $validated): self
    {
        return new self(
            minPrice: isset($validated['min_price']) ? (float) $validated['min_price'] : null,
            maxPrice: isset($validated['max_price']) ? (float) $validated['max_price'] : null,
            categoryId: isset($validated['category_id']) ? (int) $validated['category_id'] : null,
            condition: $validated['condition'] ?? null,
            q: $validated['q'] ?? null,
            showAll: filter_var($validated['show_all'] ?? false, FILTER_VALIDATE_BOOLEAN),
            page: isset($validated['page']) ? (int) $validated['page'] : 1,
            perPage: isset($validated['per_page']) ? (int) $validated['per_page'] : self::DEFAULT_PER_PAGE,
        );
    }
}
