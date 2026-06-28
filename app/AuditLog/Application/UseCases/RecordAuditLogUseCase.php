<?php

declare(strict_types=1);

namespace App\AuditLog\Application\UseCases;

use App\AuditLog\Application\Commands\RecordAuditLogCommand;
use App\AuditLog\Domain\Contracts\AuditLogRepositoryPort;
use App\AuditLog\Domain\Entities\AuditLogEntry;
use App\AuditLog\Domain\ValueObjects\AuditAction;
use App\AuditLog\Domain\ValueObjects\AuditMessage;
use App\AuditLog\Domain\ValueObjects\EventId;

/**
 * Records an audit log entry from a consumed domain event (DESIGN §IV).
 *
 * Flow:
 *   1. Resolve the AuditAction from the command.
 *   2. Build the AuditLogEntry, arming the legible message.
 *   3. Persist via the idempotent repository port (duplicate event_id ignored).
 *
 * Uses ONLY data carried by the command (event payload). No HTTP, no SQL, no
 * Eloquent, and never queries the Listings context.
 */
final class RecordAuditLogUseCase
{
    public function __construct(
        private readonly AuditLogRepositoryPort $repository,
    ) {}

    public function execute(RecordAuditLogCommand $command): void
    {
        $action = AuditAction::from($command->action);

        $changedFields = $action === AuditAction::Updated
            ? array_keys($command->snapshot['changes'] ?? [])
            : [];

        $entry = AuditLogEntry::record(
            eventId: new EventId($command->eventId),
            userId: $command->userId,
            listingId: $command->listingId,
            action: $action,
            message: AuditMessage::forListing(
                $action,
                $command->listingTitle,
                $command->listingId,
                $command->userId,
                $changedFields,
            ),
            metadata: $command->snapshot,
        );

        $this->repository->save($entry);
    }
}
