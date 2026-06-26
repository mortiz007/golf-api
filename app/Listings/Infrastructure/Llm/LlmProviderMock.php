<?php

declare(strict_types=1);

namespace App\Listings\Infrastructure\Llm;

use App\Listings\Domain\Contracts\LlmPort;
use App\Listings\Domain\Llm\EnrichmentInput;
use App\Listings\Domain\Llm\EnrichmentResult;
use App\Listings\Domain\Llm\ModerationInput;
use App\Listings\Domain\Llm\ModerationResult;
use App\Listings\Domain\ValueObjects\ListingCondition;
use App\Listings\Domain\ValueObjects\ModerationStatus;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Deterministic in-process LLM adapter for local/testing (DESIGN §V.3, Q3=A).
 *
 * Switchable with a real adapter via the LlmPort binding, without touching the
 * domain. No external calls are made (SPECS #12: LLM without external source).
 *
 * - Moderation: approved by default; rejected when the content contains "scam"
 *   or a suspicious URL.
 * - Enrichment: simple textual evaluation plus a heuristic market value
 *   (price * factor_by_condition).
 */
final class LlmProviderMock implements LlmPort
{
    private const MODEL = 'mock-llm-v1';

    /**
     * Depreciation factor applied to the listed price by item condition.
     *
     * @var array<string, float>
     */
    private const CONDITION_FACTORS = [
        ListingCondition::NEW => 1.0,
        ListingCondition::LIKE_NEW => 0.9,
        ListingCondition::REFURBISHED => 0.8,
        ListingCondition::USED => 0.65,
    ];

    public function moderate(ModerationInput $input): ModerationResult
    {
        $content = mb_strtolower($input->title.' '.$input->description);

        if ($this->isDisallowed($content)) {
            return new ModerationResult(
                status: ModerationStatus::REJECTED,
                labels: ['spam', 'scam'],
                scores: ['spam' => 0.95, 'scam' => 0.92],
                explanation: 'Content rejected: scam indicators or suspicious URLs detected.',
                model: self::MODEL,
                timestamp: $this->now(),
            );
        }

        return new ModerationResult(
            status: ModerationStatus::APPROVED,
            labels: [],
            scores: ['spam' => 0.02, 'scam' => 0.01],
            explanation: 'No policy violations detected.',
            model: self::MODEL,
            timestamp: $this->now(),
        );
    }

    public function enrich(EnrichmentInput $input): EnrichmentResult
    {
        $factor = self::CONDITION_FACTORS[$input->condition] ?? 0.5;
        $value = round($input->price * $factor, 2);
        $low = round($value * 0.9, 2);
        $high = round($value * 1.1, 2);

        return new EnrichmentResult(
            modelEvaluation: [
                'summary' => sprintf(
                    '"%s" is a %s golf item; condition and listed price suggest fair market positioning.',
                    $input->title,
                    mb_strtolower($input->condition),
                ),
                'features' => [
                    'condition: '.$input->condition,
                    'category: golf equipment',
                ],
                'confidence' => 0.7,
            ],
            estimatedMarketValue: [
                'value' => $value,
                'currency' => 'USD',
                'confidence_interval' => [$low, $high],
                'confidence' => 0.7,
                'basis' => 'Heuristic estimate based on listed price and item condition.',
            ],
            model: self::MODEL,
            generatedAt: $this->now(),
        );
    }

    private function isDisallowed(string $content): bool
    {
        if (str_contains($content, 'scam')) {
            return true;
        }

        return preg_match('#https?://|www\.#i', $content) === 1;
    }

    private function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->format('Y-m-d\TH:i:s\Z');
    }
}
