<?php

declare(strict_types=1);

use App\Listings\Domain\Entities\Listing;
use App\Listings\Domain\Events\ListingDeleted;
use App\Listings\Domain\ValueObjects\Description;
use App\Listings\Domain\ValueObjects\ListingCondition;
use App\Listings\Domain\ValueObjects\Price;
use App\Listings\Domain\ValueObjects\Title;
use App\Listings\Domain\ValueObjects\Uuid;

function deletedListing(): Listing
{
    return Listing::create(
        userId: 45,
        categoryId: 1,
        title: new Title('Driver X'),
        price: new Price(199.99),
        condition: new ListingCondition('Used'),
        description: new Description('Great club for sale here'),
    )->withId(123);
}

it('builds the normative envelope structure', function () {
    $event = new ListingDeleted(deletedListing());
    $payload = $event->toArray();

    expect($payload)->toHaveKeys([
        'event_id', 'event_version', 'occurred_at', 'user_id', 'listing_id', 'listing_snapshot',
    ])->and($payload['event_version'])->toBe(1)
        ->and($payload['user_id'])->toBe(45)
        ->and($payload['listing_id'])->toBe(123);
});

it('carries only the title in the snapshot', function () {
    $event = new ListingDeleted(deletedListing());
    $snapshot = $event->listingSnapshot;

    expect($snapshot)->toBe(['title' => 'Driver X']);
});

it('honors an injected event id for determinism', function () {
    $uuid = Uuid::v4();
    $event = new ListingDeleted(deletedListing(), eventId: $uuid);

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

    expect(fn () => new ListingDeleted($listing))->toThrow(InvalidArgumentException::class);
});
