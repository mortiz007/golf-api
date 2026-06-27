<?php

declare(strict_types=1);

namespace App\Listings\Infrastructure\Llm;

use App\Listings\Domain\Llm\EnrichmentResult;
use App\Listings\Domain\Llm\ModerationResult;
use App\Listings\Domain\ValueObjects\ModerationStatus;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\Log;

/**
 * Validates a decoded Ollama JSON payload against the normative shapes
 * (DESIGN §V.4) and maps it to the domain result DTOs.
 *
 * Pure of transport concerns. On any contract violation it logs the structured
 * outcome and throws {@see OllamaException} so the existing retry/fallback/DLQ
 * behavior applies unchanged. Adapter-owned fields (model, timestamps, the
 * forced USD currency) are set here rather than trusted from the model.
 */
final class OllamaResponseMapper
{
    private const CURRENCY = 'USD';

    /**
     * @param  array<string, mixed>  $payload
     */
    public function toModerationResult(array $payload, string $model): ModerationResult
    {
        $this->assertModerationShape($payload);

        return new ModerationResult(
            status: ModerationStatus::from($payload['status']),
            labels: array_values(array_map('strval', $payload['labels'])),
            scores: $this->normalizeScores($payload['scores']),
            explanation: (string) $payload['explanation'],
            model: $model,
            timestamp: $this->now(),
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function toEnrichmentResult(array $payload, string $model): EnrichmentResult
    {
        $this->assertEnrichmentShape($payload);

        $evaluation = $payload['model_evaluation'];
        $value = $payload['estimated_market_value'];

        return new EnrichmentResult(
            modelEvaluation: [
                'summary' => (string) $evaluation['summary'],
                'features' => array_values(array_map('strval', $evaluation['features'])),
                'confidence' => (float) $evaluation['confidence'],
            ],
            estimatedMarketValue: [
                'value' => (float) $value['value'],
                'currency' => self::CURRENCY,
                'confidence_interval' => array_values(array_map('floatval', $value['confidence_interval'])),
                'confidence' => (float) $value['confidence'],
                'basis' => (string) $value['basis'],
            ],
            model: $model,
            generatedAt: $this->now(),
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function assertModerationShape(array $payload): void
    {
        $status = $payload['status'] ?? null;

        if (! in_array($status, [ModerationStatus::APPROVED->value, ModerationStatus::REJECTED->value], true)) {
            $this->fail('moderate', 'status must be one of approved|rejected');
        }

        if (! is_array($payload['labels'] ?? null)) {
            $this->fail('moderate', 'labels must be an array');
        }

        if (! is_array($payload['scores'] ?? null)) {
            $this->fail('moderate', 'scores must be an object');
        }

        if (! is_string($payload['explanation'] ?? null)) {
            $this->fail('moderate', 'explanation must be a string');
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function assertEnrichmentShape(array $payload): void
    {
        $evaluation = $payload['model_evaluation'] ?? null;

        if (! is_array($evaluation)) {
            $this->fail('enrich', 'model_evaluation must be an object');
        }

        if (! is_string($evaluation['summary'] ?? null)) {
            $this->fail('enrich', 'model_evaluation.summary must be a string');
        }

        if (! is_array($evaluation['features'] ?? null)) {
            $this->fail('enrich', 'model_evaluation.features must be an array');
        }

        if (! $this->isNumeric($evaluation['confidence'] ?? null)) {
            $this->fail('enrich', 'model_evaluation.confidence must be numeric');
        }

        $value = $payload['estimated_market_value'] ?? null;

        if (! is_array($value)) {
            $this->fail('enrich', 'estimated_market_value must be an object');
        }

        if (! $this->isNumeric($value['value'] ?? null)) {
            $this->fail('enrich', 'estimated_market_value.value must be numeric');
        }

        if (! is_array($value['confidence_interval'] ?? null)) {
            $this->fail('enrich', 'estimated_market_value.confidence_interval must be an array');
        }

        if (! $this->isNumeric($value['confidence'] ?? null)) {
            $this->fail('enrich', 'estimated_market_value.confidence must be numeric');
        }

        if (! is_string($value['basis'] ?? null)) {
            $this->fail('enrich', 'estimated_market_value.basis must be a string');
        }
    }

    private function fail(string $operation, string $reason): never
    {
        Log::warning('ollama.outcome', ['operation' => $operation, 'outcome' => 'invalid_schema', 'reason' => $reason]);

        throw OllamaException::invalidSchema($reason);
    }

    private function isNumeric(mixed $value): bool
    {
        return is_int($value) || is_float($value);
    }

    /**
     * @param  array<string, mixed>  $scores
     * @return array<string, float>
     */
    private function normalizeScores(array $scores): array
    {
        $normalized = [];

        foreach ($scores as $label => $score) {
            $normalized[(string) $label] = (float) $score;
        }

        return $normalized;
    }

    private function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->format('Y-m-d\TH:i:s\Z');
    }
}
