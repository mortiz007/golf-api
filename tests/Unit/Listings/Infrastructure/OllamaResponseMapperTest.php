<?php

declare(strict_types=1);

use App\Listings\Domain\ValueObjects\ModerationStatus;
use App\Listings\Infrastructure\Llm\OllamaException;
use App\Listings\Infrastructure\Llm\OllamaResponseMapper;
use Tests\TestCase;

uses(TestCase::class);

it('maps a valid approved moderation payload to the normative result', function () {
    $result = (new OllamaResponseMapper)->toModerationResult([
        'status' => 'approved',
        'labels' => [],
        'scores' => ['spam' => 0.01, 'scam' => 0.02],
        'explanation' => 'No policy violations detected.',
    ], 'qwen2.5-coder:7b');

    expect($result->status)->toBe(ModerationStatus::APPROVED)
        ->and($result->labels)->toBe([])
        ->and($result->scores)->toBe(['spam' => 0.01, 'scam' => 0.02])
        ->and($result->explanation)->toBe('No policy violations detected.')
        ->and($result->model)->toBe('qwen2.5-coder:7b');
});

it('maps a valid rejected moderation payload to the normative result', function () {
    $result = (new OllamaResponseMapper)->toModerationResult([
        'status' => 'rejected',
        'labels' => ['scam'],
        'scores' => ['scam' => 0.95],
        'explanation' => 'Scam indicators detected.',
    ], 'qwen2.5-coder:7b');

    expect($result->status)->toBe(ModerationStatus::REJECTED)
        ->and($result->labels)->toBe(['scam']);
});

it('maps a valid enrichment payload and forces currency to USD', function () {
    $result = (new OllamaResponseMapper)->toEnrichmentResult([
        'model_evaluation' => [
            'summary' => 'A used driver in fair condition.',
            'features' => ['condition: Used'],
            'confidence' => 0.7,
        ],
        'estimated_market_value' => [
            'value' => 129.99,
            'currency' => 'EUR',
            'confidence_interval' => [110.0, 150.0],
            'confidence' => 0.6,
            'basis' => 'Listed price and condition.',
        ],
    ], 'qwen2.5-coder:7b');

    expect($result->estimatedMarketValue['value'])->toBe(129.99)
        ->and($result->estimatedMarketValue['currency'])->toBe('USD')
        ->and($result->estimatedMarketValue['confidence_interval'])->toBe([110.0, 150.0])
        ->and($result->modelEvaluation['confidence'])->toBe(0.7)
        ->and($result->model)->toBe('qwen2.5-coder:7b');
});

it('throws when moderation status is outside approved|rejected', function () {
    (new OllamaResponseMapper)->toModerationResult([
        'status' => 'pending',
        'labels' => [],
        'scores' => [],
        'explanation' => 'unsure',
    ], 'qwen2.5-coder:7b');
})->throws(OllamaException::class);

it('throws when enrichment is missing estimated_market_value', function () {
    (new OllamaResponseMapper)->toEnrichmentResult([
        'model_evaluation' => [
            'summary' => 'x',
            'features' => [],
            'confidence' => 0.5,
        ],
    ], 'qwen2.5-coder:7b');
})->throws(OllamaException::class);

it('throws when an estimated market value field has an invalid type', function () {
    (new OllamaResponseMapper)->toEnrichmentResult([
        'model_evaluation' => [
            'summary' => 'x',
            'features' => [],
            'confidence' => 0.5,
        ],
        'estimated_market_value' => [
            'value' => 'not-a-number',
            'confidence_interval' => [1.0, 2.0],
            'confidence' => 0.5,
            'basis' => 'x',
        ],
    ], 'qwen2.5-coder:7b');
})->throws(OllamaException::class);
