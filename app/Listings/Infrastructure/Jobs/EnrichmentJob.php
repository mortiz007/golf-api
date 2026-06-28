<?php

declare(strict_types=1);

namespace App\Listings\Infrastructure\Jobs;

use App\Listings\Application\UseCases\EnrichListingUseCase;
use App\Listings\Domain\Contracts\ListingRepositoryPort;
use App\Listings\Domain\ValueObjects\AiEnrichmentStatus;
use App\Listings\Infrastructure\Llm\OllamaException;
use App\Support\Telemetry;
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

    public function handle(EnrichListingUseCase $useCase, Telemetry $telemetry): void
    {
        $startedAt = microtime(true);

        $telemetry->event('job.start', [
            'job' => 'enrichment',
            'listing_id' => $this->listingId,
            'attempt' => $this->attempts(),
        ]);

        $outcome = 'success';

        try {
            if (! $useCase->execute($this->listingId)) {
                // The listing disappeared between dispatch and execution; record
                // the skip so the no-op is observable rather than silent.
                $telemetry->event('job.skipped', [
                    'job' => 'enrichment',
                    'listing_id' => $this->listingId,
                    'reason' => 'listing_not_found',
                ]);
            }
        } catch (OllamaException $exception) {
            $outcome = 'error';

            // Permanent contract violations cannot succeed on retry: fail
            // immediately so the job goes straight to the DLQ and the fallback
            // runs, instead of burning the full backoff cycle.
            if (! $exception->isRetryable()) {
                $this->fail($exception);

                return;
            }

            throw $exception;
        } catch (Throwable $exception) {
            $outcome = 'error';

            throw $exception;
        } finally {
            $telemetry->event('job.outcome', [
                'job' => 'enrichment',
                'listing_id' => $this->listingId,
                'attempt' => $this->attempts(),
                'outcome' => $outcome,
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            ]);
        }
    }

    /**
     * Definitive-failure fallback (DESIGN §V.2 / decision Q2=A): mark enrichment
     * as failed and record the error.
     */
    public function failed(Throwable $exception): void
    {
        app(Telemetry::class)->event('job.failed', [
            'job' => 'enrichment',
            'listing_id' => $this->listingId,
            'attempt' => $this->attempts(),
            'outcome' => 'failed',
            'exception' => $exception::class,
        ], 'warning');

        try {
            app(ListingRepositoryPort::class)->updateEnrichment(
                $this->listingId,
                [
                    'error' => $exception->getMessage(),
                    'failed_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z'),
                ],
                AiEnrichmentStatus::FAILED,
            );
        } catch (Throwable $fallbackException) {
            // The fallback persistence itself failed (e.g. DB unavailable). Surface
            // it on the telemetry pipeline so the silent loss is observable.
            app(Telemetry::class)->event('job.fallback_failed', [
                'job' => 'enrichment',
                'listing_id' => $this->listingId,
                'exception' => $fallbackException::class,
            ], 'error');
        }
    }
}
