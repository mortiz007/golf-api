<?php

declare(strict_types=1);

namespace App\Listings\Infrastructure\Llm;

use App\Listings\Domain\Contracts\LlmPort;
use App\Listings\Domain\Llm\EnrichmentInput;
use App\Listings\Domain\Llm\EnrichmentResult;
use App\Listings\Domain\Llm\ModerationInput;
use App\Listings\Domain\Llm\ModerationResult;
use App\Listings\Domain\ValueObjects\ModerationStatus;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Real LLM adapter backed by a local Ollama server (POST /api/chat).
 *
 * Interchangeable with {@see LlmProviderMock} via the LlmPort binding and
 * config/llm.php (LLM_PROVIDER), without touching the domain (ADR-011).
 *
 * Output is mapped strictly to the normative shapes in DESIGN §V.4. On any
 * transport or contract violation an {@see OllamaException} is thrown so the
 * existing retry/backoff/fallback/DLQ behavior applies unchanged — a degraded
 * result is never returned.
 */
final class OllamaLlmProvider implements LlmPort
{
    private const CURRENCY = 'USD';

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $model,
        private readonly int $timeout,
        private readonly float $temperature,
        private readonly string $keepAlive,
    ) {}

    public function moderate(ModerationInput $input): ModerationResult
    {
        $payload = $this->chat('moderate', [
            ['role' => 'system', 'content' => $this->moderationSystemPrompt()],
            ['role' => 'user', 'content' => $this->moderationUserPrompt($input)],
        ]);

        $this->assertModerationShape($payload);

        return new ModerationResult(
            status: ModerationStatus::from($payload['status']),
            labels: array_values(array_map('strval', $payload['labels'])),
            scores: $this->normalizeScores($payload['scores']),
            explanation: (string) $payload['explanation'],
            model: $this->model,
            timestamp: $this->now(),
        );
    }

    public function enrich(EnrichmentInput $input): EnrichmentResult
    {
        $payload = $this->chat('enrich', [
            ['role' => 'system', 'content' => $this->enrichmentSystemPrompt()],
            ['role' => 'user', 'content' => $this->enrichmentUserPrompt($input)],
        ]);

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
            model: $this->model,
            generatedAt: $this->now(),
        );
    }

    /**
     * Sends a chat completion request to Ollama and returns the decoded JSON
     * object carried by message.content.
     *
     * @param  array<int, array{role: string, content: string}>  $messages
     * @return array<string, mixed>
     */
    private function chat(string $operation, array $messages): array
    {
        $endpoint = rtrim($this->baseUrl, '/').'/api/chat';

        Log::info('ollama.request', [
            'operation' => $operation,
            'endpoint' => $endpoint,
            'model' => $this->model,
        ]);

        try {
            $response = Http::timeout($this->timeout)
                ->acceptJson()
                ->asJson()
                ->post($endpoint, [
                    'model' => $this->model,
                    'format' => 'json',
                    'stream' => false,
                    'keep_alive' => $this->keepAlive,
                    'options' => ['temperature' => $this->temperature],
                    'messages' => $messages,
                ])
                ->throw();
        } catch (ConnectionException $e) {
            Log::warning('ollama.outcome', ['operation' => $operation, 'outcome' => 'connection_error']);

            throw OllamaException::connection($e);
        } catch (RequestException $e) {
            $status = $e->response->status();
            Log::warning('ollama.outcome', ['operation' => $operation, 'outcome' => 'http_error', 'status' => $status]);

            throw OllamaException::httpStatus($status);
        }

        $content = (string) data_get($response->json(), 'message.content', '');
        $decoded = json_decode($content, true);

        if (! is_array($decoded)) {
            Log::warning('ollama.outcome', ['operation' => $operation, 'outcome' => 'invalid_json']);

            throw OllamaException::invalidJson();
        }

        Log::info('ollama.outcome', ['operation' => $operation, 'outcome' => 'success']);

        return $decoded;
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

    private function moderationSystemPrompt(): string
    {
        return <<<'PROMPT'
        You are a strict content moderation system for a golf equipment marketplace.
        Classify the listing for policy violations (scams, fraud, prohibited or
        suspicious content). Respond with ONLY a JSON object, no prose, matching
        exactly this schema:
        {
          "status": "approved" | "rejected",
          "labels": string[],
          "scores": { "<label>": number },
          "explanation": string
        }
        Use "rejected" only when a policy violation is detected; otherwise "approved".
        Scores are confidence values between 0 and 1.
        PROMPT;
    }

    private function moderationUserPrompt(ModerationInput $input): string
    {
        return json_encode([
            'title' => $input->title,
            'description' => $input->description,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
    }

    private function enrichmentSystemPrompt(): string
    {
        return <<<'PROMPT'
        You are a golf equipment appraisal assistant. Evaluate the listing and
        estimate its fair market value from the title, description, condition and
        listed price alone (no external sources). Respond with ONLY a JSON object,
        no prose, matching exactly this schema:
        {
          "model_evaluation": {
            "summary": string,
            "features": string[],
            "confidence": number
          },
          "estimated_market_value": {
            "value": number,
            "confidence_interval": [number, number],
            "confidence": number,
            "basis": string
          }
        }
        Confidence values are between 0 and 1. Do not include a currency field.
        PROMPT;
    }

    private function enrichmentUserPrompt(EnrichmentInput $input): string
    {
        return json_encode([
            'title' => $input->title,
            'description' => $input->description,
            'price' => $input->price,
            'condition' => $input->condition,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
    }

    private function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->format('Y-m-d\TH:i:s\Z');
    }
}
