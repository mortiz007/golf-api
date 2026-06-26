<?php

declare(strict_types=1);

namespace App\AuditLog\Application\UseCases;

use App\AuditLog\Domain\Contracts\AuditLogRepositoryPort;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Lists the audit log entries owned by a single user (SPECS §4.5).
 *
 * Returns the user's entries ordered created_at DESC, paginated. It only ever
 * reads entries scoped to the authenticated user and never touches the Listings
 * context.
 */
final class QueryAuditLogsUseCase
{
    public function __construct(
        private readonly AuditLogRepositoryPort $repository,
    ) {}

    public function execute(int $userId, int $page): LengthAwarePaginator
    {
        return $this->repository->findByUser($userId, $page);
    }
}
