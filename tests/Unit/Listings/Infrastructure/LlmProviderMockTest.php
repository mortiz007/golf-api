<?php

declare(strict_types=1);

use App\Listings\Domain\Llm\EnrichmentInput;
use App\Listings\Domain\Llm\ModerationInput;
use App\Listings\Domain\ValueObjects\ModerationStatus;
use App\Listings\Infrastructure\Llm\LlmProviderMock;

it('approves clean content by default', function () {
    $result = (new LlmProviderMock)->moderate(
        new ModerationInput('Driver Pro', 'A great club for sale')
    );

    expect($result->status)->toBe(ModerationStatus::APPROVED);
});

it('rejects content flagged as scam or with suspicious urls', function (string $title, string $description) {
    $result = (new LlmProviderMock)->moderate(new ModerationInput($title, $description));

    expect($result->status)->toBe(ModerationStatus::REJECTED);
})->with([
    'scam keyword' => ['Driver Pro', 'This is a total scam, beware'],
    'http url' => ['Driver Pro', 'Visit http://cheap-clubs.example to buy'],
    'www url' => ['Driver Pro', 'Order at www.cheap-clubs.example now'],
]);

it('estimates market value as price times the condition factor', function (string $condition, float $expected) {
    $result = (new LlmProviderMock)->enrich(
        new EnrichmentInput('Driver Pro', 'A great club', 100.0, $condition)
    );

    expect($result->estimatedMarketValue['value'])->toBe($expected)
        ->and($result->estimatedMarketValue['currency'])->toBe('USD');
})->with([
    'New' => ['New', 100.0],
    'Like New' => ['Like New', 90.0],
    'Refurbished' => ['Refurbished', 80.0],
    'Used' => ['Used', 65.0],
]);
