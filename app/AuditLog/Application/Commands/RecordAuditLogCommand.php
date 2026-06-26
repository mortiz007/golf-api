<?php

declare(strict_types=1);

namespace App\AuditLog\Application\Commands;

/**
 * Immutable input DTO for the RecordAuditLog use case (S2-06).
 *
 * Carries ONLY data taken from the consumed domain event payload. It never
 * references Listings entities, models or repositories: the AuditLog context
 * persists exactly what the event provides (DESIGN §IV.1).
 *
 * `action` is the resolved AuditAction value (created/updated/deleted), mapped
 * from the event type by the listener (S2-09). `snapshot` is the event's
 * listing_snapshot, persisted verbatim as metadata.
 */
final class RecordAuditLogCommand
{
    /**
     * @param  array<string, mixed>  $snapshot  Event listing_snapshot (metadata).
     */
    public function __construct(
        public readonly string $eventId,
        public readonly int $userId,
        public readonly string $action,
        public readonly int $listingId,
        public readonly string $listingTitle,
        public readonly array $snapshot,
    ) {}
}
