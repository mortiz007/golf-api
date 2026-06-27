<?php

declare(strict_types=1);

use App\Listings\Domain\Contracts\LlmPort;
use App\Listings\Domain\Llm\EnrichmentInput;
use App\Listings\Domain\Llm\ModerationInput;
use App\Listings\Domain\ValueObjects\ModerationStatus;
use App\Listings\Infrastructure\Llm\LlmProviderMock;
use App\Listings\Infrastructure\Llm\OllamaException;
use App\Listings\Infrastructure\Llm\OllamaLlmProvider;
use App\Listings\Infrastructure\Llm\OllamaPromptBuilder;
use App\Listings\Infrastructure\Llm\OllamaResponseMapper;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class);

/**
 * Builds the provider with deterministic settings (no real network access).
 */
function makeOllamaProvider(): OllamaLlmProvider
{
    return new OllamaLlmProvider(
        baseUrl: 'http://localhost:11434',
        model: 'qwen2.5-coder:7b',
        timeout: 60,
        temperature: 0.1,
        keepAlive: '5m',
        prompts: new OllamaPromptBuilder,
        mapper: new OllamaResponseMapper,
    );
}

/**
 * Wraps a JSON payload into the Ollama /api/chat (stream:false) envelope, where
 * message.content carries the model's JSON string.
 *
 * @param  array<string, mixed>|string  $content
 */
function fakeOllamaChat(array|string $content): array
{
    return [
        'model' => 'qwen2.5-coder:7b',
        'message' => [
            'role' => 'assistant',
            'content' => is_string($content) ? $content : json_encode($content),
        ],
        'done' => true,
    ];
}

it('maps a valid approved moderation response to the normative result', function () {
    Http::fake([
        '*/api/chat' => Http::response(fakeOllamaChat([
            'status' => 'approved',
            'labels' => [],
            'scores' => ['spam' => 0.01, 'scam' => 0.02],
            'explanation' => 'No policy violations detected.',
        ])),
    ]);

    $result = makeOllamaProvider()->moderate(new ModerationInput('Driver Pro', 'A great club for sale'));

    expect($result->status)->toBe(ModerationStatus::APPROVED)
        ->and($result->labels)->toBe([])
        ->and($result->scores)->toBe(['spam' => 0.01, 'scam' => 0.02])
        ->and($result->explanation)->toBe('No policy violations detected.')
        ->and($result->model)->toBe('qwen2.5-coder:7b');
});

it('maps a valid rejected moderation response to the normative result', function () {
    Http::fake([
        '*/api/chat' => Http::response(fakeOllamaChat([
            'status' => 'rejected',
            'labels' => ['scam'],
            'scores' => ['scam' => 0.95],
            'explanation' => 'Scam indicators detected.',
        ])),
    ]);

    $result = makeOllamaProvider()->moderate(new ModerationInput('Free clubs', 'This is a total scam'));

    expect($result->status)->toBe(ModerationStatus::REJECTED)
        ->and($result->labels)->toBe(['scam'])
        ->and($result->model)->toBe('qwen2.5-coder:7b');
});

it('maps a valid enrichment response and forces currency to USD', function () {
    Http::fake([
        '*/api/chat' => Http::response(fakeOllamaChat([
            'model_evaluation' => [
                'summary' => 'A used driver in fair condition.',
                'features' => ['condition: Used', 'category: golf equipment'],
                'confidence' => 0.7,
            ],
            'estimated_market_value' => [
                'value' => 129.99,
                'currency' => 'EUR',
                'confidence_interval' => [110.0, 150.0],
                'confidence' => 0.6,
                'basis' => 'Listed price and condition.',
            ],
        ])),
    ]);

    $result = makeOllamaProvider()->enrich(new EnrichmentInput('Driver Pro', 'A great club', 199.99, 'Used'));

    expect($result->modelEvaluation['summary'])->toBe('A used driver in fair condition.')
        ->and($result->modelEvaluation['confidence'])->toBe(0.7)
        ->and($result->estimatedMarketValue['value'])->toBe(129.99)
        ->and($result->estimatedMarketValue['currency'])->toBe('USD')
        ->and($result->estimatedMarketValue['confidence_interval'])->toBe([110.0, 150.0])
        ->and($result->model)->toBe('qwen2.5-coder:7b');
});

it('sends a request honoring the Ollama chat contract', function () {
    Http::fake([
        '*/api/chat' => Http::response(fakeOllamaChat([
            'status' => 'approved',
            'labels' => [],
            'scores' => [],
            'explanation' => 'ok',
        ])),
    ]);

    makeOllamaProvider()->moderate(new ModerationInput('Driver Pro', 'A great club'));

    Http::assertSent(function (Request $request) {
        return str_ends_with($request->url(), '/api/chat')
            && $request['model'] === 'qwen2.5-coder:7b'
            && $request['format'] === 'json'
            && $request['stream'] === false;
    });
});

it('throws when Ollama returns a non-2xx status', function () {
    Http::fake(['*/api/chat' => Http::response('boom', 500)]);

    makeOllamaProvider()->moderate(new ModerationInput('Driver Pro', 'A great club'));
})->throws(OllamaException::class);

it('throws when message.content is not valid JSON', function () {
    Http::fake(['*/api/chat' => Http::response(fakeOllamaChat('not-json-at-all'))]);

    makeOllamaProvider()->moderate(new ModerationInput('Driver Pro', 'A great club'));
})->throws(OllamaException::class);

it('throws when moderation status is outside approved|rejected', function () {
    Http::fake([
        '*/api/chat' => Http::response(fakeOllamaChat([
            'status' => 'pending',
            'labels' => [],
            'scores' => [],
            'explanation' => 'unsure',
        ])),
    ]);

    makeOllamaProvider()->moderate(new ModerationInput('Driver Pro', 'A great club'));
})->throws(OllamaException::class);

it('throws when enrichment is missing estimated_market_value', function () {
    Http::fake([
        '*/api/chat' => Http::response(fakeOllamaChat([
            'model_evaluation' => [
                'summary' => 'x',
                'features' => [],
                'confidence' => 0.5,
            ],
        ])),
    ]);

    makeOllamaProvider()->enrich(new EnrichmentInput('Driver Pro', 'A great club', 199.99, 'Used'));
})->throws(OllamaException::class);

it('resolves LlmPort to the Ollama adapter when configured', function () {
    config(['llm.provider' => 'ollama']);

    expect($this->app->make(LlmPort::class))->toBeInstanceOf(OllamaLlmProvider::class);
});

it('resolves LlmPort to the mock adapter when configured', function () {
    config(['llm.provider' => 'mock']);

    expect($this->app->make(LlmPort::class))->toBeInstanceOf(LlmProviderMock::class);
});
