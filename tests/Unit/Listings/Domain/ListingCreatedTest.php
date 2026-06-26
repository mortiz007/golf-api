<?php

declare(strict_types=1);

use App\Listings\Domain\Entities\Listing;
use App\Listings\Domain\Events\ListingCreated;
use App\Listings\Domain\ValueObjects\Description;
use App\Listings\Domain\ValueObjects\EndDate;
use App\Listings\Domain\ValueObjects\ListingCondition;
use App\Listings\Domain\ValueObjects\Price;
use App\Listings\Domain\ValueObjects\Title;
use App\Listings\Domain\ValueObjects\Uuid;

function persistedListing(): Listing
{
    return Listing::create(
        userId: 45,
        categoryId: 1,
        title: new Title('Driver Pro'),
        price: new Price(199.99),
        condition: new ListingCondition('Used'),
        description: new Description('Great club for sale here'),
        endDate: new EndDate((new DateTimeImmutable('+10 days'))->format('Y-m-d')),
    )->withId(123);
}

it('builds the normative payload structure', function () {
    $event = new ListingCreated(persistedListing());
    $payload = $event->toArray();

    expect($payload)->toHaveKeys([
        'event_id', 'event_version', 'occurred_at', 'user_id', 'listing_id', 'listing_snapshot',
    ])->and($payload['event_version'])->toBe(1)
        ->and($payload['user_id'])->toBe(45)
        ->and($payload['listing_id'])->toBe(123);
});

it('builds a minimal snapshot without ai/moderation result', function () {
    $event = new ListingCreated(persistedListing());
    $snapshot = $event->listingSnapshot;

    expect($snapshot)->toHaveKeys([
        'id', 'title', 'price', 'condition', 'description',
        'category_id', 'moderation_status', 'created_at', 'end_date',
    ])->and($snapshot)->not->toHaveKey('ai_enrichment')
        ->and($snapshot)->not->toHaveKey('moderation_result')
        ->and($snapshot['moderation_status'])->toBe('pending');
});

it('honors an injected event id for determinism', function () {
    $uuid = Uuid::v4();
    $event = new ListingCreated(persistedListing(), eventId: $uuid);

    expect($event->eventId)->toBe((string) $uuid);
});

it('rejects building the event from a non-persisted listing', function () {
    $listing = Listing::create(
        userId: 1,
        categoryId: 1,
        title: new Title('Driver Pro'),
        price: new Price(10),
        condition: new ListingCondition('New'),
        description: new Description('Great club for sale here'),
    );

    expect(fn () => new ListingCreated($listing))->toThrow(InvalidArgumentException::class);
});

it('generates valid RFC 4122 v4 uuids', function () {
    expect((string) Uuid::v4())->toMatch(
        '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/'
    );
});
