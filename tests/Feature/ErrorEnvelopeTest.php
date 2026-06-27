<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

it('returns the normative envelope with UNAUTHENTICATED on a 401', function () {
    $this->getJson('/api/audit-logs')
        ->assertUnauthorized()
        ->assertJsonPath('error.code', 'UNAUTHENTICATED')
        ->assertJsonStructure([
            'error' => ['code', 'message', 'details'],
        ]);
});

it('returns the normative envelope with RATE_LIMITED and headers on a 429', function () {
    Sanctum::actingAs(User::factory()->create());

    for ($i = 0; $i < 60; $i++) {
        $this->getJson('/api/audit-logs')->assertOk();
    }

    $this->getJson('/api/audit-logs')
        ->assertStatus(429)
        ->assertHeader('Retry-After')
        ->assertJsonPath('error.code', 'RATE_LIMITED')
        ->assertJsonStructure([
            'error' => ['code', 'message', 'details'],
        ]);
});
