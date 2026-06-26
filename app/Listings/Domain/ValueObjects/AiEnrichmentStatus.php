<?php

declare(strict_types=1);

namespace App\Listings\Domain\ValueObjects;

/**
 * AI enrichment lifecycle states. (SPECS §3)
 */
enum AiEnrichmentStatus: string
{
    case PENDING = 'pending';
    case SUCCEEDED = 'succeeded';
    case FAILED = 'failed';
}
