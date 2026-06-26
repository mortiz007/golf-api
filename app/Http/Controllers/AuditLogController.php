<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\AuditLog\Application\UseCases\QueryAuditLogsUseCase;
use App\Http\Resources\AuditLogResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * HTTP entry point for the AuditLog context (SPECS §4.5).
 *
 * Cross-cutting controller (DESIGN §II, Q12=A): it lives in app/Http, not in
 * Infrastructure. Thin by design — resolves the authenticated user, invokes the
 * query Use Case and returns a Resource. No business rules, no Listings repos.
 */
final class AuditLogController extends Controller
{
    /**
     * GET /api/audit-logs — list the authenticated user's audit logs,
     * newest first, paginated (SPECS §4.5).
     */
    public function index(Request $request, QueryAuditLogsUseCase $useCase): AnonymousResourceCollection
    {
        $page = max(1, (int) $request->query('page', '1'));

        $logs = $useCase->execute((int) $request->user()->id, $page);

        return AuditLogResource::collection($logs);
    }
}
