<?php

declare(strict_types=1);

namespace App\AuditLog\Infrastructure\Mappers;

use App\AuditLog\Domain\Entities\AuditLogEntry;
use App\AuditLog\Domain\ValueObjects\AuditAction;
use App\AuditLog\Domain\ValueObjects\AuditMessage;
use App\AuditLog\Domain\ValueObjects\EventId;
use App\AuditLog\Infrastructure\Eloquent\AuditLogModel;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Translator between the AuditLogEntry domain entity and the persistable
 * attributes of the `listing_audit_logs` table. Used ONLY by
 * EloquentAuditLogRepository (S2-08).
 *
 * `listing_id` is derived from the event snapshot (metadata['id']): the frozen
 * entity factory carries it inside the payload metadata rather than as a
 * standalone field (S2-02 decision).
 */
final class AuditLogMapper
{
    /**
     * Domain entity → array of persistable attributes (for idempotent insert).
     *
     * @return array<string, mixed>
     */
    public function toAttributes(AuditLogEntry $entry): array
    {
        $metadata = $entry->metadata();

        return [
            'event_id' => (string) $entry->eventId(),
            'user_id' => $entry->userId(),
            'listing_id' => (int) ($metadata['id'] ?? 0),
            'action' => $entry->action()->value,
            'message' => (string) $entry->message(),
            'metadata' => $metadata,
        ];
    }

    /**
     * Eloquent model → domain entity (rehydration via AuditLogEntry::fromState).
     */
    public function toDomain(AuditLogModel $model): AuditLogEntry
    {
        return AuditLogEntry::fromState(
            id: (int) $model->id,
            eventId: new EventId($model->event_id),
            userId: (int) $model->user_id,
            action: AuditAction::from($model->action),
            message: new AuditMessage($model->message),
            metadata: $model->metadata ?? [],
            createdAt: $this->toImmutable($model->created_at),
        );
    }

    /**
     * Converts the model's created_at (Carbon|string|null) to DateTimeImmutable (UTC).
     */
    private function toImmutable(mixed $value): DateTimeImmutable
    {
        if ($value instanceof \DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($value);
        }

        if (is_string($value) && $value !== '') {
            return new DateTimeImmutable($value);
        }

        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
}
