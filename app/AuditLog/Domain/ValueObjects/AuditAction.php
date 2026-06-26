<?php

declare(strict_types=1);

namespace App\AuditLog\Domain\ValueObjects;

/**
 * Business fact being audited (SPECS #18): listing created/updated/deleted.
 *
 * Backed value matches the `action` column; the verb feeds the human-readable
 * audit message (e.g. "Created listing 'X' ...").
 */
enum AuditAction: string
{
    case Created = 'created';
    case Updated = 'updated';
    case Deleted = 'deleted';

    /** Past-tense verb used to build the legible audit message. */
    public function verb(): string
    {
        return ucfirst($this->value);
    }
}
