<?php

declare(strict_types=1);

use App\Listings\Domain\Events\ListingCreated;
use App\Listings\Infrastructure\Jobs\EnrichmentJob;
use App\Listings\Infrastructure\Jobs\ModerationJob;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * Seeds a single category and returns its id (the exists rule needs a real row).
 */
function seedCategory(string $name = 'Drivers'): int
{
    return (int) DB::table('categories')->insertGetId([
        'name' => $name,
        'created_at' => now(),
    ]);
}

/**
 * @return array<string, mixed>
 */
function validListingPayload(int $categoryId): array
{
    return [
        'title' => 'Driver Pro',
        'price' => 199.99,
        'condition' => 'Used',
        'description' => 'Great club for sale here',
        'category_id' => $categoryId,
    ];
}

it('creates a listing and returns 201 with a Location header', function () {
    Queue::fake();
    Event::fake([ListingCreated::class]);

    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $categoryId = seedCategory();

    $response = $this->postJson('/api/listings', validListingPayload($categoryId));

    $response->assertCreated()
        ->assertJsonPath('title', 'Driver Pro')
        ->assertJsonPath('moderation_status', 'pending')
        ->assertJsonPath('ai_enrichment_status', 'pending')
        ->assertJsonPath('ai_enrichment', null)
        ->assertJsonPath('user.name', $user->name);

    $id = $response->json('id');
    $response->assertHeader('Location', "/api/listings/{$id}");

    $this->assertDatabaseHas('listings', [
        'id' => $id,
        'user_id' => $user->id,
        'category_id' => $categoryId,
        'title' => 'Driver Pro',
        'moderation_status' => 'pending',
        'ai_enrichment_status' => 'pending',
    ]);
});

it('enqueues moderation and enrichment jobs and dispatches the domain event', function () {
    Queue::fake();
    Event::fake([ListingCreated::class]);

    Sanctum::actingAs(User::factory()->create());
    $categoryId = seedCategory();

    $this->postJson('/api/listings', validListingPayload($categoryId))->assertCreated();

    Queue::assertPushed(ModerationJob::class);
    Queue::assertPushed(EnrichmentJob::class);
    Event::assertDispatched(ListingCreated::class);
});

it('returns 422 with the normative error envelope on invalid data', function () {
    Sanctum::actingAs(User::factory()->create());

    $response = $this->postJson('/api/listings', [
        'title' => 'Driver 3000',   // digits not allowed
        'price' => 0,               // below minimum 0.01
        'condition' => 'Broken',     // not an allowed condition
        'description' => 'short',     // shorter than 10 chars
        'category_id' => 999999,      // does not exist
    ]);

    $response->assertUnprocessable()
        ->assertJsonPath('error.code', 'VALIDATION_ERROR')
        ->assertJsonStructure([
            'error' => ['code', 'message', 'details'],
        ]);
});

it('returns 401 when no token is provided', function () {
    $categoryId = seedCategory();

    $this->postJson('/api/listings', validListingPayload($categoryId))
        ->assertUnauthorized();
});
