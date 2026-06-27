<?php

declare(strict_types=1);

use App\Listings\Domain\Events\ListingCreated;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

it('caps listing creation per user per day and returns the RATE_LIMITED envelope', function () {
    Queue::fake();
    Event::fake([ListingCreated::class]);
    config(['listings.daily_creation_limit' => 3]);

    Sanctum::actingAs(User::factory()->create());

    $categoryId = (int) DB::table('categories')->insertGetId([
        'name' => 'Drivers',
        'created_at' => now(),
    ]);

    $payload = [
        'title' => 'Driver Pro',
        'price' => 199.99,
        'condition' => 'Used',
        'description' => 'Great club for sale here',
        'category_id' => $categoryId,
    ];

    for ($i = 0; $i < 3; $i++) {
        $this->postJson('/api/listings', $payload)->assertCreated();
    }

    $this->postJson('/api/listings', $payload)
        ->assertStatus(429)
        ->assertHeader('Retry-After')
        ->assertJsonPath('error.code', 'RATE_LIMITED')
        ->assertJsonStructure([
            'error' => ['code', 'message', 'details'],
        ]);
});

it('scopes the daily cap per user', function () {
    Queue::fake();
    Event::fake([ListingCreated::class]);
    config(['listings.daily_creation_limit' => 1]);

    $categoryId = (int) DB::table('categories')->insertGetId([
        'name' => 'Drivers',
        'created_at' => now(),
    ]);

    $payload = [
        'title' => 'Driver Pro',
        'price' => 199.99,
        'condition' => 'Used',
        'description' => 'Great club for sale here',
        'category_id' => $categoryId,
    ];

    Sanctum::actingAs(User::factory()->create());
    $this->postJson('/api/listings', $payload)->assertCreated();
    $this->postJson('/api/listings', $payload)->assertStatus(429);

    // A different user is not affected by the first user's cap.
    Sanctum::actingAs(User::factory()->create());
    $this->postJson('/api/listings', $payload)->assertCreated();
});
