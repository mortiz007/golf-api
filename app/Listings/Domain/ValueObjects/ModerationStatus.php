<?php

declare(strict_types=1);

namespace App\Listings\Domain\ValueObjects;

/**
 * Moderation lifecycle states. (SPECS #1 — no "flagged")
 */
enum ModerationStatus: string
{
    case PENDING = 'pending';
    case APPROVED = 'approved';
    case REJECTED = 'rejected';
}
