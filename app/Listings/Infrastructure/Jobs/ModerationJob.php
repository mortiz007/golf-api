<?php

declare(strict_types=1);

namespace App\Listings\Infrastructure\Jobs;

use App\Listings\Application\UseCases\ModerateListingUseCase;
use App\Listings\Domain\Contracts\ListingRepositoryPort;
use App\Listings\Domain\ValueObjects\ModerationStatus;
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
 * Asynchronous moderation job (DESIGN §V).
 *
 * Thin Infrastructure adapter that delegates to the ModerateListingUseCase
 * (the layering diagram in DESIGN §II has jobs invoke use cases).
 *
 * Retry policy (DESIGN §V.2): 3 attempts, exponential backoff 5s/15s/30s.
 * Definitive failure → moderation_status stays `pending` (not visible),
 * the error is recorded in moderation_result; job moved to failed_jobs (DLQ).
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

    public function handle(ModerateListingUseCase $useCase, Telemetry $telemetry): void
    {
        $startedAt = microtime(true);

        $telemetry->event('job.start', [
            'job' => 'moderation',
            'listing_id' => $this->listingId,
            'attempt' => $this->attempts(),
        ]);

        $outcome = 'success';

        try {
            $useCase->execute($this->listingId);
        } catch (Throwable $exception) {
            $outcome = 'error';

            throw $exception;
        } finally {
            $telemetry->event('job.outcome', [
                'job' => 'moderation',
                'listing_id' => $this->listingId,
                'attempt' => $this->attempts(),
                'outcome' => $outcome,
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            ]);
        }
    }

    /**
     * Definitive-failure fallback (DESIGN §V.2 / decision Q2=A): keep the
     * listing not visible (moderation_status = pending) and record the error.
     */
    public function failed(Throwable $exception): void
    {
        app(Telemetry::class)->event('job.failed', [
            'job' => 'moderation',
            'listing_id' => $this->listingId,
            'attempt' => $this->attempts(),
            'outcome' => 'failed',
        ], 'warning');

        app(ListingRepositoryPort::class)->updateModerationResult(
            $this->listingId,
            [
                'error' => $exception->getMessage(),
                'failed_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z'),
            ],
            ModerationStatus::PENDING,
        );
    }
}
