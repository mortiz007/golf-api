<?php

declare(strict_types=1);

namespace App\Listings\Infrastructure\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Asynchronous enrichment job (DESIGN §V).
 *
 * STUB for the POST /api/listings slice. Independent of ModerationJob
 * (enrichment does NOT depend on the moderation result).
 *
 * Retry policy (DESIGN §V.2): 3 attempts, exponential backoff 5s/15s/30s.
 * Definitive failure → ai_enrichment_status = failed (error in metadata);
 * job moved to failed_jobs (DLQ).
 */
final class EnrichmentJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public function __construct(
        public readonly int $listingId,
    ) {}

    /**
     * Exponential backoff between retries, in seconds.
     *
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [5, 15, 30];
    }

    public function handle(): void
    {
        // TODO (LLM slice): resolve LlmPort, call enrich(), persist
        // ai_enrichment + ai_enrichment_status (succeeded).
        // On definitive failure set ai_enrichment_status = failed.
    }
}
