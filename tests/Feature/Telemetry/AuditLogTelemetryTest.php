<?php

declare(strict_types=1);

use App\Listings\Domain\Entities\Listing;
use App\Listings\Domain\Events\ListingCreated;
use App\Listings\Domain\ValueObjects\Description;
use App\Listings\Domain\ValueObjects\ListingCondition;
use App\Listings\Domain\ValueObjects\Price;
use App\Listings\Domain\ValueObjects\Title;
use App\Listings\Domain\ValueObjects\Uuid;
use App\Support\Telemetry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CapturingLogger;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['queue.connections.database' => ['driver' => 'sync']]);
});

it('emits job.start and job.outcome for the audit log listener', function () {
    $logger = new CapturingLogger;
    app()->instance(Telemetry::class, new Telemetry($logger));

    $listing = Listing::create(
        userId: 45,
        categoryId: 1,
        title: new Title('Driver X'),
        price: new Price(199.99),
        condition: new ListingCondition('Used'),
        description: new Description('A great driver for sale'),
        endDate: null,
    )->withId(123);

    event(new ListingCreated($listing, new Uuid('550e8400-e29b-41d4-a716-446655440000')));

    $start = $logger->eventsNamed('job.start');
    $outcome = $logger->eventsNamed('job.outcome');

    expect($start)->toHaveCount(1)
        ->and($start[0]['context']['job'])->toBe('audit_log')
        ->and($start[0]['context']['listing_id'])->toBe(123)
        ->and($outcome)->toHaveCount(1)
        ->and($outcome[0]['context']['job'])->toBe('audit_log')
        ->and($outcome[0]['context']['outcome'])->toBe('success')
        ->and($outcome[0]['context'])->toHaveKey('duration_ms');
});

it('never logs the listing title in audit telemetry', function () {
    $logger = new CapturingLogger;
    app()->instance(Telemetry::class, new Telemetry($logger));

    $listing = Listing::create(
        userId: 45,
        categoryId: 1,
        title: new Title('Secret Title'),
        price: new Price(199.99),
        condition: new ListingCondition('Used'),
        description: new Description('A great driver for sale'),
        endDate: null,
    )->withId(123);

    event(new ListingCreated($listing, new Uuid('550e8400-e29b-41d4-a716-446655440000')));

    expect(json_encode($logger->events))->not->toContain('Secret Title');
});
