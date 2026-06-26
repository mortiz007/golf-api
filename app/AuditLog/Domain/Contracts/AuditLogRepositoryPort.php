<?php

declare(strict_types=1);

namespace App\AuditLog\Domain\Contracts;

use App\AuditLog\Domain\Entities\AuditLogEntry;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Outbound port for audit log persistence (Hexagonal architecture).
 *
 * Defined in the Domain layer; the concrete adapter
 * (EloquentAuditLogRepository) lives in Infrastructure (S2-08) and is bound
 * via ServiceProvider (S2-10).
 *
 * The Domain depends only on this contract — never on Eloquent.
 */
interface AuditLogRepositoryPort
{
    /**
     * Persists an audit log entry with idempotent semantics.
     *
     * Idempotency is keyed on the entry's `event_id` (UNIQUE constraint): if an
     * entry with the same event_id already exists, the call is a no-op — it
     * neither raises an error nor inserts a duplicate row (DESIGN §IV.1).
     */
    public function save(AuditLogEntry $entry): void;

    /**
     * Returns the entries owned by a single user, newest first, paginated.
     *
     * Scoped strictly to the given user (SPECS §4.5): WHERE user_id = ?
     * ORDER BY created_at DESC. Items are AuditLogEntry domain objects.
     *
     * @return LengthAwarePaginator<int, AuditLogEntry>
     */
    public function findByUser(int $userId, int $page): LengthAwarePaginator;
}
