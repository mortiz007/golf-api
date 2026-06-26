<?php

declare(strict_types=1);

use App\Listings\Domain\Llm\EnrichmentInput;
use App\Listings\Domain\Llm\EnrichmentResult;
use App\Listings\Domain\Llm\ModerationInput;
use App\Listings\Domain\Llm\ModerationResult;
use App\Listings\Domain\ValueObjects\ModerationStatus;

it('exposes the moderation input attributes', function () {
    $input = new ModerationInput('Driver Pro', 'A great club for sale');

    expect($input->title)->toBe('Driver Pro')
        ->and($input->description)->toBe('A great club for sale');
});

it('serializes the moderation result into the normative shape', function () {
    $result = new ModerationResult(
        status: ModerationStatus::APPROVED,
        labels: ['spam'],
        scores: ['spam' => 0.02],
        explanation: 'No policy violations detected.',
        model: 'mock-llm-v1',
        timestamp: '2026-06-26T12:00:00Z',
    );

    expect($result->status)->toBe(ModerationStatus::APPROVED)
        ->and($result->toArray())->toBe([
            'status' => 'approved',
            'labels' => ['spam'],
            'scores' => ['spam' => 0.02],
            'explanation' => 'No policy violations detected.',
            'model' => 'mock-llm-v1',
            'timestamp' => '2026-06-26T12:00:00Z',
        ]);
});

it('exposes the enrichment input attributes', function () {
    $input = new EnrichmentInput('Driver Pro', 'A great club', 199.99, 'Used');

    expect($input->title)->toBe('Driver Pro')
        ->and($input->description)->toBe('A great club')
        ->and($input->price)->toBe(199.99)
        ->and($input->condition)->toBe('Used');
});

it('serializes the enrichment result into the normative shape', function () {
    $result = new EnrichmentResult(
        modelEvaluation: ['summary' => 'Solid item.', 'features' => ['durable'], 'confidence' => 0.7],
        estimatedMarketValue: [
            'value' => 129.99,
            'currency' => 'USD',
            'confidence_interval' => [116.99, 142.99],
            'confidence' => 0.7,
            'basis' => 'Heuristic estimate.',
        ],
        model: 'mock-llm-v1',
        generatedAt: '2026-06-26T12:00:00Z',
    );

    expect($result->toArray())->toBe([
        'model_evaluation' => ['summary' => 'Solid item.', 'features' => ['durable'], 'confidence' => 0.7],
        'estimated_market_value' => [
            'value' => 129.99,
            'currency' => 'USD',
            'confidence_interval' => [116.99, 142.99],
            'confidence' => 0.7,
            'basis' => 'Heuristic estimate.',
        ],
        'model' => 'mock-llm-v1',
        'generated_at' => '2026-06-26T12:00:00Z',
    ]);
});
