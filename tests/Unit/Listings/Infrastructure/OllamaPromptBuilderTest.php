<?php

declare(strict_types=1);

use App\Listings\Domain\Llm\EnrichmentInput;
use App\Listings\Domain\Llm\ModerationInput;
use App\Listings\Infrastructure\Llm\OllamaPromptBuilder;

it('builds moderation messages with a system schema and a user content', function () {
    $messages = (new OllamaPromptBuilder)->moderationMessages(
        new ModerationInput('Driver Pro', 'A great club for sale')
    );

    expect($messages)->toHaveCount(2)
        ->and($messages[0]['role'])->toBe('system')
        ->and($messages[1]['role'])->toBe('user');

    $user = json_decode($messages[1]['content'], true);
    expect($user)->toBe([
        'title' => 'Driver Pro',
        'description' => 'A great club for sale',
    ]);
});

it('builds enrichment messages carrying price and condition', function () {
    $messages = (new OllamaPromptBuilder)->enrichmentMessages(
        new EnrichmentInput('Driver Pro', 'A great club', 199.99, 'Used')
    );

    expect($messages)->toHaveCount(2)
        ->and($messages[0]['role'])->toBe('system')
        ->and($messages[1]['role'])->toBe('user');

    $user = json_decode($messages[1]['content'], true);
    expect($user)->toBe([
        'title' => 'Driver Pro',
        'description' => 'A great club',
        'price' => 199.99,
        'condition' => 'Used',
    ]);
});
