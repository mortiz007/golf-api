<?php

declare(strict_types=1);

use App\Listings\Domain\Events\ListingUpdated;
use App\Listings\Infrastructure\Eloquent\ListingModel;
use App\Listings\Infrastructure\Jobs\EnrichmentJob;
use App\Listings\Infrastructure\Jobs\ModerationJob;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function seedCategoryForUpdate(string $name = 'Drivers'): int
{
    return (int) DB::table('categories')->insertGetId([
        'name' => $name,
        'created_at' => now(),
    ]);
}

/**
 * Persists an approved/succeeded listing owned by $userId and returns its id.
 */
function seedListing(int $userId, int $categoryId, array $overrides = []): int
{
    return (int) ListingModel::create(array_merge([
        'user_id' => $userId,
        'category_id' => $categoryId,
        'title' => 'Driver Pro',
        'price' => 199.99,
        'condition' => 'Used',
        'description' => 'Great club for sale here',
        'moderation_status' => 'approved',
        'ai_enrichment_status' => 'succeeded',
    ], $overrides))->id;
}

it('updates the title, resets moderation to pending and re-queues moderation', function () {
    Queue::fake();
    Event::fake([ListingUpdated::class]);

    $user = User::factory()->create();
    Sanctum::actingAs($user);
    $categoryId = seedCategoryForUpdate();
    $id = seedListing($user->id, $categoryId);

    $response = $this->patchJson("/api/listings/{$id}", ['title' => 'Brand New Driver']);

    $response->assertOk()
        ->assertJsonPath('title', 'Brand New Driver')
        ->assertJsonPath('moderation_status', 'pending');

    $this->assertDatabaseHas('listings', [
        'id' => $id,
        'title' => 'Brand New Driver',
        'moderation_status' => 'pending',
        'ai_enrichment_status' => 'succeeded',
    ]);

    Queue::assertPushed(ModerationJob::class);
    Queue::assertNotPushed(EnrichmentJob::class);
    Event::assertDispatched(ListingUpdated::class);
});

it('updates price and condition, resets enrichment and re-queues enrichment', function () {
    Queue::fake();
    Event::fake([ListingUpdated::class]);

    $user = User::factory()->create();
    Sanctum::actingAs($user);
    $categoryId = seedCategoryForUpdate();
    $id = seedListing($user->id, $categoryId);

    $this->patchJson("/api/listings/{$id}", ['price' => 250.5, 'condition' => 'New'])
        ->assertOk()
        ->assertJsonPath('ai_enrichment_status', 'pending');

    $this->assertDatabaseHas('listings', [
        'id' => $id,
        'condition' => 'New',
        'ai_enrichment_status' => 'pending',
        'moderation_status' => 'approved',
    ]);

    Queue::assertPushed(EnrichmentJob::class);
    Queue::assertNotPushed(ModerationJob::class);
});

it('returns 200 without dispatching ListingUpdated when no field actually changes', function () {
    Queue::fake();
    Event::fake([ListingUpdated::class]);

    $user = User::factory()->create();
    Sanctum::actingAs($user);
    $categoryId = seedCategoryForUpdate();
    $id = seedListing($user->id, $categoryId);

    $this->patchJson("/api/listings/{$id}", ['title' => 'Driver Pro', 'price' => 199.99])
        ->assertOk()
        ->assertJsonPath('title', 'Driver Pro');

    Event::assertNotDispatched(ListingUpdated::class);
    Queue::assertNotPushed(ModerationJob::class);
    Queue::assertNotPushed(EnrichmentJob::class);
});

it('does not re-queue jobs when only category_id changes', function () {
    Queue::fake();

    $user = User::factory()->create();
    Sanctum::actingAs($user);
    $categoryId = seedCategoryForUpdate();
    $otherCategoryId = seedCategoryForUpdate('Putters');
    $id = seedListing($user->id, $categoryId);

    $this->patchJson("/api/listings/{$id}", ['category_id' => $otherCategoryId])->assertOk();

    Queue::assertNotPushed(ModerationJob::class);
    Queue::assertNotPushed(EnrichmentJob::class);
});

it('returns 403 when the actor is not the owner', function () {
    $owner = User::factory()->create();
    $categoryId = seedCategoryForUpdate();
    $id = seedListing($owner->id, $categoryId);

    Sanctum::actingAs(User::factory()->create());

    $this->patchJson("/api/listings/{$id}", ['title' => 'Hacked Title'])
        ->assertForbidden()
        ->assertJsonPath('error.code', 'FORBIDDEN');
});

it('returns 404 when the listing does not exist', function () {
    Sanctum::actingAs(User::factory()->create());

    $this->patchJson('/api/listings/999999', ['title' => 'Nope'])
        ->assertNotFound()
        ->assertJsonPath('error.code', 'NOT_FOUND');
});

it('returns 404 when the listing is cancelled', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    $categoryId = seedCategoryForUpdate();
    $id = seedListing($user->id, $categoryId, ['cancelled_at' => now()]);

    $this->patchJson("/api/listings/{$id}", ['title' => 'Anything'])
        ->assertNotFound()
        ->assertJsonPath('error.code', 'NOT_FOUND');
});

it('returns 422 with the normative envelope on an invalid field', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    $categoryId = seedCategoryForUpdate();
    $id = seedListing($user->id, $categoryId);

    $this->patchJson("/api/listings/{$id}", ['title' => 'Driver 3000'])
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'VALIDATION_ERROR')
        ->assertJsonStructure(['error' => ['code', 'message', 'details']]);
});

it('returns 401 when no token is provided', function () {
    $owner = User::factory()->create();
    $categoryId = seedCategoryForUpdate();
    $id = seedListing($owner->id, $categoryId);

    $this->patchJson("/api/listings/{$id}", ['title' => 'New Title'])
        ->assertUnauthorized();
});
