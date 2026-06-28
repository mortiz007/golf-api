<?php

declare(strict_types=1);

namespace App\AuditLog\Domain\Entities;

use App\AuditLog\Domain\ValueObjects\AuditAction;
use App\AuditLog\Domain\ValueObjects\AuditMessage;
use App\AuditLog\Domain\ValueObjects\EventId;
use DateTimeImmutable;

/**
 * Audit log entry domain entity (AuditLog bounded context).
 *
 * Pure and framework-agnostic: it knows nothing about Eloquent. Every field is
 * derived exclusively from the consumed domain event payload (DESIGN §IV.1).
 * The `metadata` array is the event snapshot persisted verbatim.
 *
 * Identity (`id`) and `createdAt` are null on a freshly recorded entry; both are
 * populated when rehydrating a persisted entry via fromState() (read side).
 */
final class AuditLogEntry
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    private function __construct(
        private readonly ?int $id,
        private readonly EventId $eventId,
        private readonly int $userId,
        private readonly int $listingId,
        private readonly AuditAction $action,
        private readonly AuditMessage $message,
        private readonly array $metadata,
        private readonly ?DateTimeImmutable $createdAt,
    ) {}

    /**
     * Factory recording an audited fact from event payload data.
     *
     * @param  array<string, mixed>  $metadata  Event snapshot persisted as-is.
     */
    public static function record(
        EventId $eventId,
        int $userId,
        int $listingId,
        AuditAction $action,
        AuditMessage $message,
        array $metadata,
    ): self {
        return new self(
            id: null,
            eventId: $eventId,
            userId: $userId,
            listingId: $listingId,
            action: $action,
            message: $message,
            metadata: $metadata,
            createdAt: null,
        );
    }

    /**
     * Rehydration factory for a persisted entry (used by the mapper on reads).
     *
     * @param  array<string, mixed>  $metadata
     */
    public static function fromState(
        int $id,
        EventId $eventId,
        int $userId,
        int $listingId,
        AuditAction $action,
        AuditMessage $message,
        array $metadata,
        DateTimeImmutable $createdAt,
    ): self {
        return new self(
            id: $id,
            eventId: $eventId,
            userId: $userId,
            listingId: $listingId,
            action: $action,
            message: $message,
            metadata: $metadata,
            createdAt: $createdAt,
        );
    }

    public function id(): ?int
    {
        return $this->id;
    }

    public function eventId(): EventId
    {
        return $this->eventId;
    }

    public function userId(): int
    {
        return $this->userId;
    }

    public function listingId(): int
    {
        return $this->listingId;
    }

    public function action(): AuditAction
    {
        return $this->action;
    }

    public function message(): AuditMessage
    {
        return $this->message;
    }

    /**
     * @return array<string, mixed>
     */
    public function metadata(): array
    {
        return $this->metadata;
    }

    public function createdAt(): ?DateTimeImmutable
    {
        return $this->createdAt;
    }
}
