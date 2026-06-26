<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\AuditLog\Domain\Entities\AuditLogEntry;
use DateTimeImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * API representation of an audit log entry (SPECS §4.5).
 *
 * Built from the AuditLogEntry domain entity returned by QueryAuditLogsUseCase.
 * Exposes only id, action, message, metadata and created_at — never data from
 * other users (scoping is enforced by the query). Default "data" wrapping keeps
 * pagination metadata for the collection response.
 *
 * @property-read AuditLogEntry $resource
 */
final class AuditLogResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $entry = $this->resource;

        return [
            'id' => $entry->id(),
            'action' => $entry->action()->value,
            'message' => (string) $entry->message(),
            'metadata' => $entry->metadata(),
            'created_at' => $entry->createdAt()?->format(DateTimeImmutable::ATOM),
        ];
    }
}
