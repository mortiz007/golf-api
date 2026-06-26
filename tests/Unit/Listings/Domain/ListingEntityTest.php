<?php

declare(strict_types=1);

use App\Listings\Domain\Entities\Listing;
use App\Listings\Domain\ValueObjects\AiEnrichmentStatus;
use App\Listings\Domain\ValueObjects\Description;
use App\Listings\Domain\ValueObjects\EndDate;
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

it('is not cancelled when created', function () {
    $listing = makeListing();

    expect($listing->isCancelled())->toBeFalse()
        ->and($listing->cancelledAt())->toBeNull();
});

it('reports cancellation from rehydrated state', function () {
    $cancelled = Listing::fromState(
        id: 1,
        userId: 45,
        categoryId: 1,
        title: new Title('Driver Pro'),
        price: new Price(199.99),
        condition: new ListingCondition('Used'),
        description: new Description('Great club for sale here'),
        endDate: null,
        moderationStatus: ModerationStatus::APPROVED,
        aiEnrichmentStatus: AiEnrichmentStatus::SUCCEEDED,
        createdAt: new DateTimeImmutable('2026-06-01T00:00:00Z'),
        cancelledAt: new DateTimeImmutable('2026-06-02T00:00:00Z'),
    );

    expect($cancelled->isCancelled())->toBeTrue()
        ->and($cancelled->cancelledAt())->not->toBeNull();
});

it('updates fields immutably via with* methods', function () {
    $listing = makeListing()->withId(7);

    $updated = $listing
        ->withTitle(new Title('New Driver'))
        ->withPrice(new Price(250.00))
        ->withCondition(new ListingCondition('New'))
        ->withDescription(new Description('A brand new updated club'))
        ->withCategoryId(2);

    expect((string) $updated->title())->toBe('New Driver')
        ->and($updated->price()->value())->toBe(250.0)
        ->and((string) $updated->condition())->toBe('New')
        ->and((string) $updated->description())->toBe('A brand new updated club')
        ->and($updated->categoryId())->toBe(2)
        ->and($updated->id())->toBe(7)
        // original untouched
        ->and((string) $listing->title())->toBe('Driver Pro')
        ->and($listing->categoryId())->toBe(1)
        ->and($updated)->not->toBe($listing);
});

it('clears the end date immutably via withEndDate(null)', function () {
    $listing = makeListing()->withEndDate(new EndDate('2999-01-01'));

    $cleared = $listing->withEndDate(null);

    expect($cleared->endDate())->toBeNull()
        ->and($listing->endDate())->not->toBeNull();
});

it('resets statuses immutably', function () {
    $listing = Listing::fromState(
        id: 1,
        userId: 45,
        categoryId: 1,
        title: new Title('Driver Pro'),
        price: new Price(199.99),
        condition: new ListingCondition('Used'),
        description: new Description('Great club for sale here'),
        endDate: null,
        moderationStatus: ModerationStatus::APPROVED,
        aiEnrichmentStatus: AiEnrichmentStatus::SUCCEEDED,
        createdAt: new DateTimeImmutable('2026-06-01T00:00:00Z'),
    );

    $reset = $listing
        ->withModerationStatus(ModerationStatus::PENDING)
        ->withAiEnrichmentStatus(AiEnrichmentStatus::PENDING);

    expect($reset->moderationStatus())->toBe(ModerationStatus::PENDING)
        ->and($reset->aiEnrichmentStatus())->toBe(AiEnrichmentStatus::PENDING)
        // original untouched
        ->and($listing->moderationStatus())->toBe(ModerationStatus::APPROVED)
        ->and($listing->aiEnrichmentStatus())->toBe(AiEnrichmentStatus::SUCCEEDED);
});
