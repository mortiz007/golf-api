<?php

declare(strict_types=1);

namespace App\Listings\Domain\Llm;

/**
 * Immutable enrichment outcome (DESIGN §V.4).
 *
 * Shape: {
 *   model_evaluation{ summary, features[], confidence },
 *   estimated_market_value{ value, currency:"USD", confidence_interval[], confidence, basis },
 *   model, generated_at
 * }.
 */
final class EnrichmentResult
{
    /**
     * @param  array{summary: string, features: array<int, string>, confidence: float}  $modelEvaluation
     * @param  array{value: float, currency: string, confidence_interval: array<int, float>, confidence: float, basis: string}  $estimatedMarketValue
     */
    public function __construct(
        public readonly array $modelEvaluation,
        public readonly array $estimatedMarketValue,
        public readonly string $model,
        public readonly string $generatedAt,
    ) {}

    /**
     * Normative JSON shape persisted into listings.ai_enrichment.
     *
     * @return array{model_evaluation: array{summary: string, features: array<int, string>, confidence: float}, estimated_market_value: array{value: float, currency: string, confidence_interval: array<int, float>, confidence: float, basis: string}, model: string, generated_at: string}
     */
    public function toArray(): array
    {
        return [
            'model_evaluation' => $this->modelEvaluation,
            'estimated_market_value' => $this->estimatedMarketValue,
            'model' => $this->model,
            'generated_at' => $this->generatedAt,
        ];
    }
}
