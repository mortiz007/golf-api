<?php

declare(strict_types=1);

namespace App\AuditLog\Infrastructure\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Eloquent persistence model for the `listing_audit_logs` table (SPECS §3).
 *
 * IMPORTANT: this is an Infrastructure adapter, NOT a domain entity. Conversion
 * to/from the AuditLogEntry domain entity is handled by AuditLogMapper (S2-08).
 * The table carries no foreign keys to listings/users (bounded context isolation).
 *
 * Only `created_at` is managed (no `updated_at` column).
 *
 * @property int $id
 * @property int $user_id
 * @property int $listing_id
 * @property string $action
 * @property string $message
 * @property array $metadata
 * @property string $event_id
 * @property Carbon|null $created_at
 */
final class AuditLogModel extends Model
{
    protected $table = 'listing_audit_logs';

    public const UPDATED_AT = null;

    /**
     * Mass-assignable columns.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'user_id',
        'listing_id',
        'action',
        'message',
        'metadata',
        'event_id',
    ];

    /**
     * Attribute casting.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }
}
