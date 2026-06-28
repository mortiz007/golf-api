<?php

declare(strict_types=1);

namespace App\Listings\Domain\Events;

use App\Listings\Domain\Entities\Listing;
use App\Listings\Domain\ValueObjects\Uuid;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Domain event emitted right after a Listing is updated (SPECS §5 / DESIGN §IV.2).
 *
 * Immutable. The listing_snapshot carries the current title plus a `changes`
 * diff with only the user-submitted fields that actually changed, each as
 * { "old": ..., "new": ... }. System side-effects (moderation_status,
 * ai_enrichment_status) are intentionally excluded.
 */
final class ListingUpdated
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
     * @param  Listing  $listing  Updated, persisted listing (must already have an id).
     * @param  array<string, array{old: mixed, new: mixed}>  $changes  User-submitted fields that changed.
     * @param  Uuid|null  $eventId  Optional injected id (default: new UUID v4).
     * @param  DateTimeImmutable|null  $occurredAt  Optional injected timestamp (default: now UTC).
     */
    public function __construct(
        Listing $listing,
        array $changes,
        ?Uuid $eventId = null,
        ?DateTimeImmutable $occurredAt = null,
    ) {
        if ($listing->id() === null) {
            throw new InvalidArgumentException('ListingUpdated requires a persisted listing with an id.');
        }

        $this->eventId = (string) ($eventId ?? Uuid::v4());
        $this->eventVersion = self::EVENT_VERSION;
        $this->occurredAt = $occurredAt ?? new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $this->userId = $listing->userId();
        $this->listingId = $listing->id();
        $this->listingSnapshot = self::buildSnapshot($listing, $changes);
    }

    /**
     * Builds the update snapshot: the current title plus the changed-fields diff.
     *
     * @param  array<string, array{old: mixed, new: mixed}>  $changes
     * @return array<string, mixed>
     */
    private static function buildSnapshot(Listing $listing, array $changes): array
    {
        return [
            'title' => (string) $listing->title(),
            'changes' => $changes,
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
