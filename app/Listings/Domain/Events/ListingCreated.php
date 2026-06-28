<?php

declare(strict_types=1);

namespace App\Listings\Domain\Events;

use App\Listings\Domain\Entities\Listing;
use App\Listings\Domain\ValueObjects\Uuid;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Domain event emitted right after a Listing is created (SPECS §5 / DESIGN §IV.2).
 *
 * Immutable. Carries the normative payload:
 *   event_id (UUID v4), event_version, occurred_at (ISO 8601),
 *   user_id (BIGINT), listing_id (BIGINT), listing_snapshot (minimal).
 *
 * The snapshot excludes ai_enrichment and moderation_result (SPECS #17).
 */
final class ListingCreated
{
    public const EVENT_VERSION = 1;

    public readonly string $eventId;

    public readonly int $eventVersion;

    public readonly DateTimeImmutable $occurredAt;

    public readonly int $userId;

    public readonly int $listingId;

    /** @var array<string, mixed> */
    public readonly array $listingSnapshot;

    /**
     * @param  Listing  $listing  Persisted listing (must already have an id).
     * @param  Uuid|null  $eventId  Optional injected id (default: new UUID v4).
     * @param  DateTimeImmutable|null  $occurredAt  Optional injected timestamp (default: now UTC).
     */
    public function __construct(
        Listing $listing,
        ?Uuid $eventId = null,
        ?DateTimeImmutable $occurredAt = null,
    ) {
        if ($listing->id() === null) {
            throw new InvalidArgumentException('ListingCreated requires a persisted listing with an id.');
        }

        $this->eventId = (string) ($eventId ?? Uuid::v4());
        $this->eventVersion = self::EVENT_VERSION;
        $this->occurredAt = $occurredAt ?? new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $this->userId = $listing->userId();
        $this->listingId = $listing->id();
        $this->listingSnapshot = self::buildSnapshot($listing);
    }

    /**
     * Builds the minimal snapshot defined in DESIGN §IV.2.
     *
     * @return array<string, mixed>
     */
    private static function buildSnapshot(Listing $listing): array
    {
        $endDate = $listing->endDate();

        return [
            'title' => (string) $listing->title(),
            'price' => $listing->price()->value(),
            'condition' => (string) $listing->condition(),
            'description' => (string) $listing->description(),
            'category_id' => $listing->categoryId(),
            'moderation_status' => $listing->moderationStatus()->value,
            'created_at' => $listing->createdAt()->format(DateTimeImmutable::ATOM),
            'end_date' => $endDate?->toString(),
        ];
    }

    /**
     * Full normative payload as an associative array (for serialization/transport).
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'event_id' => $this->eventId,
            'event_version' => $this->eventVersion,
            'occurred_at' => $this->occurredAt->format(DateTimeImmutable::ATOM),
            'user_id' => $this->userId,
            'listing_id' => $this->listingId,
            'listing_snapshot' => $this->listingSnapshot,
        ];
    }
}
