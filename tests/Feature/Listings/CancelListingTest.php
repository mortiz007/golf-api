<?php

declare(strict_types=1);

use App\Listings\Domain\Events\ListingDeleted;
use App\Listings\Infrastructure\Eloquent\ListingModel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function seedCategoryForCancel(string $name = 'Drivers'): int
{
    return (int) DB::table('categories')->insertGetId([
        'name' => $name,
        'created_at' => now(),
    ]);
}

function seedListingForCancel(int $userId, int $categoryId, array $overrides = []): int
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

it('soft-deletes the listing, returns 204 and publishes ListingDeleted', function () {
    Event::fake([ListingDeleted::class]);

    $user = User::factory()->create();
    Sanctum::actingAs($user);
    $categoryId = seedCategoryForCancel();
    $id = seedListingForCancel($user->id, $categoryId);

    $this->deleteJson("/api/listings/{$id}")->assertNoContent();

    $listing = ListingModel::find($id);
    expect($listing->cancelled_at)->not->toBeNull();

    Event::assertDispatched(ListingDeleted::class);
});

it('records a single deleted audit log when cancelling', function () {
    config(['queue.connections.database' => ['driver' => 'sync']]);

    $user = User::factory()->create();
    Sanctum::actingAs($user);
    $categoryId = seedCategoryForCancel();
    $id = seedListingForCancel($user->id, $categoryId);

    $this->deleteJson("/api/listings/{$id}")->assertNoContent();

    $this->assertDatabaseCount('listing_audit_logs', 1);
    $this->assertDatabaseHas('listing_audit_logs', [
        'listing_id' => $id,
        'user_id' => $user->id,
        'action' => 'deleted',
    ]);
});

it('is idempotent: cancelling an already-cancelled listing stays 204 without duplicate audit', function () {
    config(['queue.connections.database' => ['driver' => 'sync']]);

    $user = User::factory()->create();
    Sanctum::actingAs($user);
    $categoryId = seedCategoryForCancel();
    $id = seedListingForCancel($user->id, $categoryId);

    $this->deleteJson("/api/listings/{$id}")->assertNoContent();
    $this->deleteJson("/api/listings/{$id}")->assertNoContent();

    $this->assertDatabaseCount('listing_audit_logs', 1);
});

it('returns 403 when the actor is not the owner', function () {
    $owner = User::factory()->create();
    $categoryId = seedCategoryForCancel();
    $id = seedListingForCancel($owner->id, $categoryId);

    Sanctum::actingAs(User::factory()->create());

    $this->deleteJson("/api/listings/{$id}")
        ->assertForbidden()
        ->assertJsonPath('error.code', 'FORBIDDEN');

    $this->assertDatabaseHas('listings', [
        'id' => $id,
        'cancelled_at' => null,
    ]);
});

it('returns 404 when the listing does not exist', function () {
    Sanctum::actingAs(User::factory()->create());

    $this->deleteJson('/api/listings/999999')
        ->assertNotFound()
        ->assertJsonPath('error.code', 'NOT_FOUND');
});

it('returns 401 when no token is provided', function () {
    $owner = User::factory()->create();
    $categoryId = seedCategoryForCancel();
    $id = seedListingForCancel($owner->id, $categoryId);

    $this->deleteJson("/api/listings/{$id}")->assertUnauthorized();
});
