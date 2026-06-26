<?php

declare(strict_types=1);

namespace App\AuditLog\Infrastructure\Repositories;

use App\AuditLog\Domain\Contracts\AuditLogRepositoryPort;
use App\AuditLog\Domain\Entities\AuditLogEntry;
use App\AuditLog\Infrastructure\Eloquent\AuditLogModel;
use App\AuditLog\Infrastructure\Mappers\AuditLogMapper;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Eloquent-backed adapter for AuditLogRepositoryPort (S2-03).
 *
 * Idempotent persistence keyed on `event_id` (UNIQUE): firstOrCreate inserts a
 * row only when the event_id is new; a duplicate event is a silent no-op,
 * neither erroring nor inserting a second row (DESIGN §IV.1).
 *
 * The only Infrastructure component allowed to touch the database for the
 * AuditLog bounded context. It never queries the Listings context.
 */
final class EloquentAuditLogRepository implements AuditLogRepositoryPort
{
    private const PER_PAGE = 20;

    public function __construct(
        private readonly AuditLogMapper $mapper,
    ) {}

    public function save(AuditLogEntry $entry): void
    {
        $attributes = $this->mapper->toAttributes($entry);

        AuditLogModel::firstOrCreate(
            ['event_id' => $attributes['event_id']],
            $attributes,
        );
    }

    public function findByUser(int $userId, int $page): LengthAwarePaginator
    {
        return AuditLogModel::query()
            ->where('user_id', $userId)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(perPage: self::PER_PAGE, page: $page)
            ->through(fn (AuditLogModel $model): AuditLogEntry => $this->mapper->toDomain($model));
    }
}
