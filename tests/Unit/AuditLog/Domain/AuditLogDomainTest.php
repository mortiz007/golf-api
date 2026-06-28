<?php

declare(strict_types=1);

use App\AuditLog\Domain\Entities\AuditLogEntry;
use App\AuditLog\Domain\ValueObjects\AuditAction;
use App\AuditLog\Domain\ValueObjects\AuditMessage;
use App\AuditLog\Domain\ValueObjects\EventId;

/* ------------------------------ AuditAction ------------------------------ */

it('exposes the three audited actions', function () {
    expect(AuditAction::Created->value)->toBe('created')
        ->and(AuditAction::Updated->value)->toBe('updated')
        ->and(AuditAction::Deleted->value)->toBe('deleted');
});

it('builds the past-tense verb for each action', function (AuditAction $action, string $verb) {
    expect($action->verb())->toBe($verb);
})->with([
    [AuditAction::Created, 'Created'],
    [AuditAction::Updated, 'Updated'],
    [AuditAction::Deleted, 'Deleted'],
]);

/* -------------------------------- EventId -------------------------------- */

it('accepts a valid UUID v4 and lowercases it', function () {
    $id = new EventId('550E8400-E29B-41D4-A716-446655440000');
    expect((string) $id)->toBe('550e8400-e29b-41d4-a716-446655440000');
});

it('rejects a malformed event id', function () {
    expect(fn () => new EventId('not-a-uuid'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new EventId(''))->toThrow(InvalidArgumentException::class);
});

it('rejects a non v4 UUID', function () {
    // Version nibble is 1, not 4.
    expect(fn () => new EventId('550e8400-e29b-11d4-a716-446655440000'))
        ->toThrow(InvalidArgumentException::class);
});

it('compares event ids for equality', function () {
    $a = new EventId('550e8400-e29b-41d4-a716-446655440000');
    $b = new EventId('550e8400-e29b-41d4-a716-446655440000');
    expect($a->equals($b))->toBeTrue();
});

/* ------------------------------ AuditMessage ----------------------------- */

it('rejects an empty audit message', function () {
    expect(fn () => new AuditMessage('   '))->toThrow(InvalidArgumentException::class);
});

it('rejects an audit message longer than 500 chars', function () {
    expect(fn () => new AuditMessage(str_repeat('a', 501)))->toThrow(InvalidArgumentException::class);
});

it('builds the normative legible message for a listing fact', function () {
    $message = AuditMessage::forListing(AuditAction::Created, 'Driver X', 123, 45);
    expect((string) $message)->toBe("Created listing 'Driver X' (id: 123) by user 45");
});

it('builds the message for updated and deleted actions', function (AuditAction $action, string $verb) {
    $message = AuditMessage::forListing($action, 'Putter Y', 9, 7);
    expect((string) $message)->toBe("{$verb} listing 'Putter Y' (id: 9) by user 7");
})->with([
    [AuditAction::Updated, 'Updated'],
    [AuditAction::Deleted, 'Deleted'],
]);

/* --------------------------- AuditLogEntry factory ----------------------- */

it('records an audit log entry from event payload data', function () {
    $metadata = ['id' => 123, 'title' => 'Driver X', 'price' => 199.99];

    $entry = AuditLogEntry::record(
        eventId: new EventId('550e8400-e29b-41d4-a716-446655440000'),
        userId: 45,
        listingId: 123,
        action: AuditAction::Created,
        message: AuditMessage::forListing(AuditAction::Created, 'Driver X', 123, 45),
        metadata: $metadata,
    );

    expect((string) $entry->eventId())->toBe('550e8400-e29b-41d4-a716-446655440000')
        ->and($entry->userId())->toBe(45)
        ->and($entry->listingId())->toBe(123)
        ->and($entry->action())->toBe(AuditAction::Created)
        ->and((string) $entry->message())->toBe("Created listing 'Driver X' (id: 123) by user 45")
        ->and($entry->metadata())->toBe($metadata);
});
