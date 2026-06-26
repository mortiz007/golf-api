<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Listings\Application\ReadModels\ListingListItem;
use DateTimeImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * API representation of a public listing item (GET /api/listings, SPECS §4.4).
 *
 * Built from the ListingListItem read model. The owner is exposed as
 * { first_name, last_name } derived from the single `name` column (first token
 * vs. remainder). Default "data" wrapping preserves pagination metadata.
 *
 * @property-read ListingListItem $resource
 */
final class ListingListItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $item = $this->resource;

        [$firstName, $lastName] = $this->splitName($item->userName);

        return [
            'id' => $item->id,
            'title' => $item->title,
            'price' => $item->price,
            'condition' => $item->condition,
            'description' => $item->description,
            'created_at' => $item->createdAt->format(DateTimeImmutable::ATOM),
            'user' => [
                'first_name' => $firstName,
                'last_name' => $lastName,
            ],
            'category' => [
                'id' => $item->categoryId,
                'name' => $item->categoryName,
            ],
            'ai_enrichment' => $item->aiEnrichment,
        ];
    }

    /**
     * Splits a full name into first token and remainder (SPECS §4.4 shape over
     * the Laravel default `name` column — ADR-002 divergence).
     *
     * @return array{0: string, 1: string}
     */
    private function splitName(string $name): array
    {
        $name = trim($name);

        if ($name === '') {
            return ['', ''];
        }

        $parts = preg_split('/\s+/', $name, 2) ?: [$name];

        return [$parts[0], $parts[1] ?? ''];
    }
}
