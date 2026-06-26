<?php

declare(strict_types=1);

namespace App\Listings\Application\Commands;

/**
 * Immutable input DTO for the CreateListing use case (S1-08).
 *
 * Carries already-validated primitive data (from StoreListingRequest, S1-14)
 * plus the authenticated actor id. No behavior, no validation, no VO building.
 */
final class CreateListingCommand
{
    public function __construct(
        public readonly int $actorUserId,
        public readonly int $categoryId,
        public readonly string $title,
        public readonly float $price,
        public readonly string $condition,
        public readonly string $description,
        public readonly ?string $endDate = null,
    ) {}

    /**
     * Factory from a validated payload (controller maps FormRequest → Command).
     *
     * @param  array<string, mixed>  $data  Validated request data.
     */
    public static function fromArray(int $actorUserId, array $data): self
    {
        return new self(
            actorUserId: $actorUserId,
            categoryId: (int) $data['category_id'],
            title: (string) $data['title'],
            price: (float) $data['price'],
            condition: (string) $data['condition'],
            description: (string) $data['description'],
            endDate: isset($data['end_date']) ? (string) $data['end_date'] : null,
        );
    }
}
