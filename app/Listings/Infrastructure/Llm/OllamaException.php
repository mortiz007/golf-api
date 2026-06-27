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
 */
final class OllamaException extends RuntimeException
{
    public static function connection(Throwable $previous): self
    {
        return new self('Ollama connection failed: '.$previous->getMessage(), 0, $previous);
    }

    public static function httpStatus(int $status): self
    {
        return new self("Ollama returned a non-2xx HTTP status: {$status}.");
    }

    public static function invalidJson(): self
    {
        return new self('Ollama message.content is not valid JSON.');
    }

    public static function invalidSchema(string $reason): self
    {
        return new self("Ollama response failed schema validation: {$reason}.");
    }
}
