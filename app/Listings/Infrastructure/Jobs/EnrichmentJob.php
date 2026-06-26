<?php

declare(strict_types=1);

namespace App\Listings\Infrastructure\Jobs;

use App\Listings\Application\UseCases\EnrichListingUseCase;
use App\Listings\Domain\Contracts\ListingRepositoryPort;
use App\Listings\Domain\ValueObjects\AiEnrichmentStatus;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Asynchronous enrichment job (DESIGN §V).
 *
 * Thin Infrastructure adapter that delegates to the EnrichListingUseCase.
 * Independent of ModerationJob (enrichment does NOT depend on moderation).
 *
 * Retry policy (DESIGN §V.2): 3 attempts, exponential backoff 5s/15s/30s.
 * Definitive failure → ai_enrichment_status = failed (error recorded in
 * ai_enrichment); job moved to failed_jobs (DLQ).
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

    public function handle(EnrichListingUseCase $useCase): void
    {
        $useCase->execute($this->listingId);
    }

    /**
     * Definitive-failure fallback (DESIGN §V.2 / decision Q2=A): mark enrichment
     * as failed and record the error.
     */
    public function failed(Throwable $exception): void
    {
        app(ListingRepositoryPort::class)->updateEnrichment(
            $this->listingId,
            [
                'error' => $exception->getMessage(),
                'failed_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z'),
            ],
            AiEnrichmentStatus::FAILED,
        );
    }
}
