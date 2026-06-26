<?php

declare(strict_types=1);

use App\Listings\Domain\Entities\Listing;
use App\Listings\Domain\ValueObjects\AiEnrichmentStatus;
use App\Listings\Domain\ValueObjects\Description;
use App\Listings\Domain\ValueObjects\ListingCondition;
use App\Listings\Domain\ValueObjects\ModerationStatus;
use App\Listings\Domain\ValueObjects\Price;
use App\Listings\Domain\ValueObjects\Title;

function makeListing(): Listing
{
    return Listing::create(
        userId: 45,
        categoryId: 1,
        title: new Title('Driver Pro'),
        price: new Price(199.99),
        condition: new ListingCondition('Used'),
        description: new Description('Great club for sale here'),
    );
}

it('creates a listing with pending initial statuses', function () {
    $listing = makeListing();

    expect($listing->moderationStatus())->toBe(ModerationStatus::PENDING)
        ->and($listing->aiEnrichmentStatus())->toBe(AiEnrichmentStatus::PENDING);
});

it('creates a listing without an id', function () {
    expect(makeListing()->id())->toBeNull();
});

it('creates a listing without an end date when omitted', function () {
    expect(makeListing()->endDate())->toBeNull();
});

it('assigns an id immutably via withId', function () {
    $listing = makeListing();
    $persisted = $listing->withId(123);

    expect($persisted->id())->toBe(123)
        ->and($listing->id())->toBeNull()         // original untouched
        ->and($persisted)->not->toBe($listing);   // returns a new instance
});

it('exposes the value objects it was built with', function () {
    $listing = makeListing();

    expect((string) $listing->title())->toBe('Driver Pro')
        ->and($listing->price()->value())->toBe(199.99)
        ->and((string) $listing->condition())->toBe('Used')
        ->and($listing->userId())->toBe(45)
        ->and($listing->categoryId())->toBe(1);
});
