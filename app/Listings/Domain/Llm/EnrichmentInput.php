<?php

declare(strict_types=1);

namespace App\Listings\Domain\Llm;

/**
 * Immutable input for AI enrichment (SPECS §6 / DESIGN §V.4).
 *
 * Carries the listing attributes the LLM needs to evaluate the item and
 * estimate its market value; framework-agnostic.
 */
final class EnrichmentInput
{
    public function __construct(
        public readonly string $title,
        public readonly string $description,
        public readonly float $price,
        public readonly string $condition,
    ) {}
}
