<?php

declare(strict_types=1);

use App\Models\User;
use App\Support\Telemetry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Tests\Support\CapturingLogger;

uses(RefreshDatabase::class);

it('returns the normative envelope with UNAUTHENTICATED on a 401', function () {
    $this->getJson('/api/v1/audit-logs')
        ->assertUnauthorized()
        ->assertJsonPath('error.code', 'UNAUTHENTICATED')
        ->assertJsonStructure([
            'error' => ['code', 'message', 'details'],
        ]);
});

it('returns the normative envelope with RATE_LIMITED and headers on a 429', function () {
    Sanctum::actingAs(User::factory()->create());

    for ($i = 0; $i < 60; $i++) {
        $this->getJson('/api/v1/audit-logs')->assertOk();
    }

    $this->getJson('/api/v1/audit-logs')
        ->assertStatus(429)
        ->assertHeader('Retry-After')
        ->assertJsonPath('error.code', 'RATE_LIMITED')
        ->assertJsonStructure([
            'error' => ['code', 'message', 'details'],
        ]);
});

it('maps an unhandled exception on an api route to the INTERNAL_ERROR envelope', function () {
    Route::get('/api/v1/_boom', function (): void {
        throw new RuntimeException('kaboom');
    });

    $this->getJson('/api/v1/_boom')
        ->assertStatus(500)
        ->assertJsonPath('error.code', 'INTERNAL_ERROR')
        ->assertJsonStructure([
            'error' => ['code', 'message', 'details'],
        ]);
});

it('does not leak the internal exception message in the INTERNAL_ERROR envelope', function () {
    Route::get('/api/v1/_boom', function (): void {
        throw new RuntimeException('super secret internal detail');
    });

    $this->getJson('/api/v1/_boom')
        ->assertStatus(500)
        ->assertJsonPath('error.message', 'An unexpected error occurred.')
        ->assertDontSee('super secret internal detail');
});

it('emits error.unhandled telemetry for an unhandled exception', function () {
    $logger = new CapturingLogger;
    app()->instance(Telemetry::class, new Telemetry($logger));

    Route::get('/api/v1/_boom', function (): void {
        throw new RuntimeException('kaboom');
    });

    $this->getJson('/api/v1/_boom')->assertStatus(500);

    $events = $logger->eventsNamed('error.unhandled');

    expect($events)->toHaveCount(1)
        ->and($events[0]['context']['exception'])->toBe(RuntimeException::class)
        ->and($events[0]['level'])->toBe('error');
});

it('does not report an expected domain exception as an unhandled error', function () {
    $logger = new CapturingLogger;
    app()->instance(Telemetry::class, new Telemetry($logger));

    Sanctum::actingAs(User::factory()->create());

    $this->patchJson('/api/v1/listings/999999', ['title' => 'New Title'])
        ->assertStatus(404)
        ->assertJsonPath('error.code', 'NOT_FOUND');

    expect($logger->eventsNamed('error.unhandled'))->toBeEmpty();
});

it('returns the JSON envelope on api routes even without an Accept header', function () {
    $this->get('/api/v1/audit-logs')
        ->assertStatus(401)
        ->assertJsonPath('error.code', 'UNAUTHENTICATED');
});
