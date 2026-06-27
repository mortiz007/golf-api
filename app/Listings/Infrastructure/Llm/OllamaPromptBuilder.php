<?php

declare(strict_types=1);

namespace App\Listings\Infrastructure\Llm;

use App\Listings\Domain\Llm\EnrichmentInput;
use App\Listings\Domain\Llm\ModerationInput;

/**
 * Builds the Ollama /api/chat message arrays for each LLM operation.
 *
 * Stateless and pure: the system message pins the normative JSON schema
 * (DESIGN §V.4) and the user message carries the listing content to evaluate.
 * Isolated from transport so prompt wording can change without touching the
 * HTTP client or the response mapper.
 */
final class OllamaPromptBuilder
{
    /**
     * @return array<int, array{role: string, content: string}>
     */
    public function moderationMessages(ModerationInput $input): array
    {
        return [
            ['role' => 'system', 'content' => $this->moderationSystemPrompt()],
            ['role' => 'user', 'content' => $this->moderationUserPrompt($input)],
        ];
    }

    /**
     * @return array<int, array{role: string, content: string}>
     */
    public function enrichmentMessages(EnrichmentInput $input): array
    {
        return [
            ['role' => 'system', 'content' => $this->enrichmentSystemPrompt()],
            ['role' => 'user', 'content' => $this->enrichmentUserPrompt($input)],
        ];
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
}
