<?php

declare(strict_types=1);

namespace App\Listings\Infrastructure\Llm;

use App\Listings\Domain\Contracts\LlmPort;
use App\Listings\Domain\Llm\EnrichmentInput;
use App\Listings\Domain\Llm\EnrichmentResult;
use App\Listings\Domain\Llm\ModerationInput;
use App\Listings\Domain\Llm\ModerationResult;
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
 * Thin orchestrator: prompt building is delegated to {@see OllamaPromptBuilder}
 * and response validation/mapping to {@see OllamaResponseMapper}; this class
 * owns only the HTTP transport. On any transport or contract violation an
 * {@see OllamaException} is thrown so the existing retry/backoff/fallback/DLQ
 * behavior applies unchanged — a degraded result is never returned.
 */
final class OllamaLlmProvider implements LlmPort
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $model,
        private readonly int $timeout,
        private readonly float $temperature,
        private readonly string $keepAlive,
        private readonly OllamaPromptBuilder $prompts,
        private readonly OllamaResponseMapper $mapper,
    ) {}

    public function moderate(ModerationInput $input): ModerationResult
    {
        $payload = $this->chat('moderate', $this->prompts->moderationMessages($input));

        return $this->mapper->toModerationResult($payload, $this->model);
    }

    public function enrich(EnrichmentInput $input): EnrichmentResult
    {
        $payload = $this->chat('enrich', $this->prompts->enrichmentMessages($input));

        return $this->mapper->toEnrichmentResult($payload, $this->model);
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
}
