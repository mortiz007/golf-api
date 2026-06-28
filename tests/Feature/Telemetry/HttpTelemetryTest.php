<?php

declare(strict_types=1);

use App\Support\Telemetry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CapturingLogger;
use Tests\TestCase;

uses(RefreshDatabase::class);

/**
 * Rebinds the Telemetry singleton to an in-memory logger so HTTP boundary
 * events can be asserted without writing to stdout.
 */
function captureTelemetry(): CapturingLogger
{
    $logger = new CapturingLogger;

    app()->instance(Telemetry::class, new Telemetry($logger));

    return $logger;
}

it('emits http.request and http.outcome for an API request', function () {
    /** @var TestCase $this */
    $logger = captureTelemetry();

    $this->getJson('/api/v1/listings')->assertOk();

    $request = $logger->eventsNamed('http.request');
    $outcome = $logger->eventsNamed('http.outcome');

    expect($request)->toHaveCount(1)
        ->and($request[0]['context']['method'])->toBe('GET')
        ->and($request[0]['context']['path'])->toBe('/api/v1/listings')
        ->and($outcome)->toHaveCount(1)
        ->and($outcome[0]['context']['status'])->toBe(200)
        ->and($outcome[0]['context']['method'])->toBe('GET')
        ->and($outcome[0]['context']['path'])->toBe('/api/v1/listings')
        ->and($outcome[0]['context'])->toHaveKey('duration_ms');
});

it('records the final status for error responses', function () {
    /** @var TestCase $this */
    $logger = captureTelemetry();

    $this->postJson('/api/v1/listings', [])->assertUnauthorized();

    $outcome = $logger->eventsNamed('http.outcome');

    expect($outcome)->toHaveCount(1)
        ->and($outcome[0]['context']['status'])->toBe(401);
});

it('never logs the query string or user content at the HTTP boundary', function () {
    /** @var TestCase $this */
    $logger = captureTelemetry();

    $this->getJson('/api/v1/listings?q=secret-search-term&min_price=10')->assertOk();

    $encoded = json_encode($logger->events);

    expect($encoded)->not->toContain('secret-search-term')
        ->and($encoded)->not->toContain('q=');

    $allowed = ['method', 'path', 'status', 'duration_ms', 'user_id'];

    foreach ($logger->events as $entry) {
        expect(array_diff(array_keys($entry['context']), $allowed))->toBe([]);
    }
});
