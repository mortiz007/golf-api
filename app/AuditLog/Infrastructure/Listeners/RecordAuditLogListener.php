<?php

declare(strict_types=1);

namespace App\AuditLog\Infrastructure\Listeners;

use App\AuditLog\Application\Commands\RecordAuditLogCommand;
use App\AuditLog\Application\UseCases\RecordAuditLogUseCase;
use App\AuditLog\Domain\ValueObjects\AuditAction;
use App\Listings\Domain\Events\ListingCreated;
use App\Listings\Domain\Events\ListingDeleted;
use App\Listings\Domain\Events\ListingUpdated;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Queued consumer of Listings domain events that records audit entries
 * (DESIGN §IV.3). Generic handler for the three audited facts (decision 2-B).
 *
 * Isolation rules: it MAY type-hint App\Listings\Domain\Events\* but never
 * imports Listings repositories/models/services. Every persisted value comes
 * exclusively from the event payload.
 *
 * Reliability (DESIGN §V.2): runs on the `database` queue with 3 tries and
 * exponential backoff [5, 15, 30]; a persistent failure lands in failed_jobs (DLQ).
 */
final class RecordAuditLogListener implements ShouldQueue
{
    public string $connection = 'database';

    public int $tries = 3;

    public function __construct(
        private readonly RecordAuditLogUseCase $useCase,
    ) {}

    /**
     * Retry backoff in seconds (DESIGN §V.2).
     *
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [5, 15, 30];
    }

    public function handle(ListingCreated|ListingUpdated|ListingDeleted $event): void
    {
        $action = match (true) {
            $event instanceof ListingCreated => AuditAction::Created,
            $event instanceof ListingUpdated => AuditAction::Updated,
            $event instanceof ListingDeleted => AuditAction::Deleted,
        };

        $snapshot = $event->listingSnapshot;

        $this->useCase->execute(new RecordAuditLogCommand(
            eventId: $event->eventId,
            userId: $event->userId,
            action: $action->value,
            listingId: $event->listingId,
            listingTitle: (string) ($snapshot['title'] ?? ''),
            snapshot: $snapshot,
        ));
    }
}
