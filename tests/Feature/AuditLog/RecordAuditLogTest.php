<?php

declare(strict_types=1);

use App\AuditLog\Infrastructure\Eloquent\AuditLogModel;
use App\Listings\Domain\Entities\Listing;
use App\Listings\Domain\Events\ListingCreated;
use App\Listings\Domain\Events\ListingDeleted;
use App\Listings\Domain\Events\ListingUpdated;
use App\Listings\Domain\ValueObjects\Description;
use App\Listings\Domain\ValueObjects\ListingCondition;
use App\Listings\Domain\ValueObjects\Price;
use App\Listings\Domain\ValueObjects\Title;
use App\Listings\Domain\ValueObjects\Uuid;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Runs the ShouldQueue listener (connection `database`) synchronously so the
 * end-to-end consumption can be asserted within the test.
 */
beforeEach(function () {
    config(['queue.connections.database' => ['driver' => 'sync']]);
});

function makeAuditListing(int $id = 123): Listing
{
    return Listing::create(
        userId: 45,
        categoryId: 1,
        title: new Title('Driver X'),
        price: new Price(199.99),
        condition: new ListingCondition('Used'),
        description: new Description('A great driver for sale'),
        endDate: null,
    )->withId($id);
}

it('records a single audit log when ListingCreated is dispatched', function () {
    $eventId = '550e8400-e29b-41d4-a716-446655440000';

    event(new ListingCreated(makeAuditListing(123), new Uuid($eventId)));

    $this->assertDatabaseCount('listing_audit_logs', 1);
    $this->assertDatabaseHas('listing_audit_logs', [
        'event_id' => $eventId,
        'user_id' => 45,
        'listing_id' => 123,
        'action' => 'created',
        'message' => "Created listing 'Driver X' (id: 123) by user 45",
    ]);

    $entry = AuditLogModel::first();
    expect($entry->metadata)->toMatchArray([
        'id' => 123,
        'title' => 'Driver X',
        'category_id' => 1,
    ]);
});

it('is idempotent: re-dispatching the same event_id keeps a single row', function () {
    $event = new ListingCreated(makeAuditListing(123), new Uuid('550e8400-e29b-41d4-a716-446655440000'));

    event($event);
    event($event);

    $this->assertDatabaseCount('listing_audit_logs', 1);
});

it('records updated and deleted facts from synthetic events', function (string $eventClass, string $action, string $verb) {
    event(new $eventClass(makeAuditListing(123)));

    $this->assertDatabaseCount('listing_audit_logs', 1);
    $this->assertDatabaseHas('listing_audit_logs', [
        'listing_id' => 123,
        'action' => $action,
        'message' => "{$verb} listing 'Driver X' (id: 123) by user 45",
    ]);
})->with([
    [ListingUpdated::class, 'updated', 'Updated'],
    [ListingDeleted::class, 'deleted', 'Deleted'],
]);
