<?php

declare(strict_types=1);

namespace App\Listings\Domain\Contracts;

use App\Listings\Domain\Llm\EnrichmentInput;
use App\Listings\Domain\Llm\EnrichmentResult;
use App\Listings\Domain\Llm\ModerationInput;
use App\Listings\Domain\Llm\ModerationResult;

/**
 * Single outbound port for LLM integration (SPECS §6 / DESIGN §V.4, Q8=B).
 *
 * Defined in the Domain layer; concrete adapters (e.g. LlmProviderMock) live in
 * Infrastructure and are bound via ServiceProvider, switchable without touching
 * the domain. The Domain depends only on this contract.
 */
interface LlmPort
{
    /**
     * Classifies the listing content and returns the moderation outcome
     * (resolved status approved or rejected).
     */
    public function moderate(ModerationInput $input): ModerationResult;

    /**
     * Generates a model evaluation and an estimated market value for the listing.
     */
    public function enrich(EnrichmentInput $input): EnrichmentResult;
}
