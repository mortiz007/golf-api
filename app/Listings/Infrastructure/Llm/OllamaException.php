<?php

declare(strict_types=1);

namespace App\Listings\Infrastructure\Llm;

use RuntimeException;
use Throwable;

/**
 * Boundary failure of the Ollama LLM adapter (DESIGN §V.2 / ADR-011).
 *
 * Thrown on any transport or contract violation so the calling job retries and,
 * on definitive failure, applies the existing fallback (moderation → pending /
 * enrichment → failed) and moves the job to the DLQ — never a degraded result.
 *
 * The retryable flag distinguishes transient transport failures (connection
 * drops, HTTP 5xx) from permanent contract violations (HTTP 4xx, malformed or
 * schema-invalid responses). Permanent failures skip the retry/backoff cycle
 * and go straight to the DLQ, since retrying cannot change the outcome.
 */
final class OllamaException extends RuntimeException
{
    private function __construct(string $message, private readonly bool $retryable, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    public static function connection(Throwable $previous): self
    {
        return new self('Ollama connection failed: '.$previous->getMessage(), true, $previous);
    }

    public static function httpStatus(int $status): self
    {
        return new self("Ollama returned a non-2xx HTTP status: {$status}.", $status >= 500);
    }

    public static function invalidJson(): self
    {
        return new self('Ollama message.content is not valid JSON.', false);
    }

    public static function invalidSchema(string $reason): self
    {
        return new self("Ollama response failed schema validation: {$reason}.", false);
    }

    /**
     * Whether retrying the operation could plausibly succeed (transient failure).
     */
    public function isRetryable(): bool
    {
        return $this->retryable;
    }
}
