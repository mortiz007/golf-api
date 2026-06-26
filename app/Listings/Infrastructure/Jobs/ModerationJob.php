<?php

declare(strict_types=1);

namespace App\Listings\Infrastructure\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Asynchronous moderation job (DESIGN §V).
 *
 * STUB for the POST /api/listings slice: establishes queue topology and retry
 * policy. The LLM classification (LlmPort::moderate) and the resulting
 * moderation_result / moderation_status write are implemented in a later slice.
 *
 * Retry policy (DESIGN §V.2): 3 attempts, exponential backoff 5s/15s/30s.
 * Definitive failure → moderation_status stays `pending` (not visible);
 * job moved to failed_jobs (DLQ).
 */
final class ModerationJob implements ShouldQueue
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
        // TODO (LLM slice): resolve LlmPort, call moderate(), persist
        // moderation_result + moderation_status (approved|rejected).
        // On definitive failure leave moderation_status = pending.
    }
}
