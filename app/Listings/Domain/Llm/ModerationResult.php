<?php

declare(strict_types=1);

namespace App\Listings\Domain\Llm;

use App\Listings\Domain\ValueObjects\ModerationStatus;

/**
 * Immutable moderation outcome (DESIGN §V.4).
 *
 * Shape: { status, labels[], scores{}, explanation, model, timestamp }.
 * The resolved {@see ModerationStatus} is approved or rejected; the pending
 * fallback is applied by the job on definitive failure, never returned here.
 */
final class ModerationResult
{
    /**
     * @param  array<int, string>  $labels
     * @param  array<string, float>  $scores
     */
    public function __construct(
        public readonly ModerationStatus $status,
        public readonly array $labels,
        public readonly array $scores,
        public readonly string $explanation,
        public readonly string $model,
        public readonly string $timestamp,
    ) {}

    /**
     * Normative JSON shape persisted into listings.moderation_result.
     *
     * @return array{status: string, labels: array<int, string>, scores: array<string, float>, explanation: string, model: string, timestamp: string}
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status->value,
            'labels' => $this->labels,
            'scores' => $this->scores,
            'explanation' => $this->explanation,
            'model' => $this->model,
            'timestamp' => $this->timestamp,
        ];
    }
}
