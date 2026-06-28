<?php

declare(strict_types=1);

use App\Listings\Infrastructure\Llm\OllamaException;

it('marks transport failures as retryable', function () {
    expect(OllamaException::connection(new RuntimeException('refused'))->isRetryable())->toBeTrue()
        ->and(OllamaException::httpStatus(503)->isRetryable())->toBeTrue()
        ->and(OllamaException::httpStatus(500)->isRetryable())->toBeTrue();
});

it('marks contract violations and 4xx responses as non-retryable', function () {
    expect(OllamaException::httpStatus(400)->isRetryable())->toBeFalse()
        ->and(OllamaException::httpStatus(422)->isRetryable())->toBeFalse()
        ->and(OllamaException::invalidJson()->isRetryable())->toBeFalse()
        ->and(OllamaException::invalidSchema('reason')->isRetryable())->toBeFalse();
});
