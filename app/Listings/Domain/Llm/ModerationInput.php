<?php

declare(strict_types=1);

namespace App\Listings\Domain\Llm;

/**
 * Immutable input for content moderation (SPECS §6 / DESIGN §V.4).
 *
 * Carries only the listing text the LLM needs to classify; framework-agnostic.
 */
final class ModerationInput
{
    public function __construct(
        public readonly string $title,
        public readonly string $description,
    ) {}
}
