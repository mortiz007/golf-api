<?php

declare(strict_types=1);

namespace App\Listings\Application\ReadModels;

use DateTimeImmutable;

/**
 * Read model for a single public listing item (GET /api/listings, SPECS §4.4).
 *
 * A dedicated query-side DTO (CQRS-lite): it carries denormalized display data
 * (owner name + category name + ai_enrichment payload) that the write-side
 * Listing entity does not hold. Immutable; built by the query repository.
 */
final class ListingListItem
{
    /**
     * @param  array<string, mixed>|null  $aiEnrichment
     */
    public function __construct(
        public readonly int $id,
        public readonly string $title,
        public readonly float $price,
        public readonly string $condition,
        public readonly string $description,
        public readonly DateTimeImmutable $createdAt,
        public readonly string $userName,
        public readonly int $categoryId,
        public readonly string $categoryName,
        public readonly ?array $aiEnrichment,
    ) {}
}
