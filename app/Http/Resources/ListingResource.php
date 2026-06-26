<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Listings\Domain\Entities\Listing;
use DateTimeImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * API representation of a freshly created Listing (HTTP 201, SPECS §4.1).
 *
 * Built from the domain Listing entity returned by CreateListingUseCase.
 * Exposes the owner as { name } (ADR-002 option 2-B) using the authenticated
 * creator, and never leaks sensitive user fields (password, email, tokens).
 *
 * @property-read Listing $resource
 */
final class ListingResource extends JsonResource
{
    /**
     * Emit a flat response (no "data" wrapper).
     *
     * @var string|null
     */
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $listing = $this->resource;

        return [
            'id' => $listing->id(),
            'title' => (string) $listing->title(),
            'price' => $listing->price()->value(),
            'condition' => (string) $listing->condition(),
            'description' => (string) $listing->description(),
            'end_date' => $listing->endDate()?->toString(),
            'category_id' => $listing->categoryId(),
            'moderation_status' => $listing->moderationStatus()->value,
            'ai_enrichment_status' => $listing->aiEnrichmentStatus()->value,
            'ai_enrichment' => null,
            'created_at' => $listing->createdAt()->format(DateTimeImmutable::ATOM),
            'user' => [
                'name' => $request->user()?->name,
            ],
        ];
    }
}
