<?php

declare(strict_types=1);

use App\Listings\Domain\Entities\Listing;
use App\Listings\Domain\Events\ListingUpdated;
use App\Listings\Domain\ValueObjects\Description;
use App\Listings\Domain\ValueObjects\ListingCondition;
use App\Listings\Domain\ValueObjects\Price;
use App\Listings\Domain\ValueObjects\Title;
use App\Listings\Domain\ValueObjects\Uuid;

function updatedListing(): Listing
{
    return Listing::create(
        userId: 45,
        categoryId: 1,
        title: new Title('New Driver'),
        price: new Price(250.00),
        condition: new ListingCondition('Used'),
        description: new Description('Great club for sale here'),
    )->withId(123);
}

it('builds the normative envelope structure', function () {
    $event = new ListingUpdated(updatedListing(), [
        'price' => ['old' => 199.99, 'new' => 250.00],
    ]);
    $payload = $event->toArray();

    expect($payload)->toHaveKeys([
        'event_id', 'event_version', 'occurred_at', 'user_id', 'listing_id', 'listing_snapshot',
    ])->and($payload['event_version'])->toBe(1)
        ->and($payload['user_id'])->toBe(45)
        ->and($payload['listing_id'])->toBe(123);
});

it('carries the current title plus the changes diff in the snapshot', function () {
    $event = new ListingUpdated(updatedListing(), [
        'title' => ['old' => 'Old Driver', 'new' => 'New Driver'],
        'price' => ['old' => 199.99, 'new' => 250.00],
    ]);
    $snapshot = $event->listingSnapshot;

    expect($snapshot)->toHaveKeys(['title', 'changes'])
        ->and($snapshot)->not->toHaveKey('id')
        ->and($snapshot['title'])->toBe('New Driver')
        ->and($snapshot['changes'])->toBe([
            'title' => ['old' => 'Old Driver', 'new' => 'New Driver'],
            'price' => ['old' => 199.99, 'new' => 250.00],
        ]);
});

it('keeps the current title in the snapshot even when title did not change', function () {
    $event = new ListingUpdated(updatedListing(), [
        'price' => ['old' => 199.99, 'new' => 250.00],
    ]);
    $snapshot = $event->listingSnapshot;

    expect($snapshot['title'])->toBe('New Driver')
        ->and($snapshot['changes'])->not->toHaveKey('title');
});

it('honors an injected event id for determinism', function () {
    $uuid = Uuid::v4();
    $event = new ListingUpdated(updatedListing(), ['price' => ['old' => 1, 'new' => 2]], eventId: $uuid);

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

    expect(fn () => new ListingUpdated($listing, []))->toThrow(InvalidArgumentException::class);
});
