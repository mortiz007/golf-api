<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function seedCategoryForList(string $name = 'Drivers'): int
{
    return (int) DB::table('categories')->insertGetId([
        'name' => $name,
        'created_at' => now(),
    ]);
}

/**
 * Inserts a listing row with full control over visibility-relevant columns.
 */
function seedListingForList(int $userId, int $categoryId, array $overrides = []): int
{
    $now = now();

    return (int) DB::table('listings')->insertGetId(array_merge([
        'user_id' => $userId,
        'category_id' => $categoryId,
        'title' => 'Titanium Driver',
        'price' => 199.99,
        'condition' => 'Used',
        'description' => 'Great club for sale here',
        'end_date' => null,
        'moderation_status' => 'approved',
        'moderation_result' => null,
        'ai_enrichment' => null,
        'ai_enrichment_status' => 'succeeded',
        'cancelled_at' => null,
        'created_at' => $now,
        'updated_at' => $now,
    ], $overrides));
}

it('returns only visible listings ordered by created_at ASC by default', function () {
    $user = User::factory()->create();
    $categoryId = seedCategoryForList();

    $older = seedListingForList($user->id, $categoryId, ['title' => 'Older Visible', 'created_at' => now()->subDays(2)]);
    $newer = seedListingForList($user->id, $categoryId, ['title' => 'Newer Visible', 'created_at' => now()->subDay()]);

    // Hidden cases.
    seedListingForList($user->id, $categoryId, ['title' => 'Pending', 'moderation_status' => 'pending']);
    seedListingForList($user->id, $categoryId, ['title' => 'Rejected', 'moderation_status' => 'rejected']);
    seedListingForList($user->id, $categoryId, ['title' => 'Cancelled', 'cancelled_at' => now()]);
    seedListingForList($user->id, $categoryId, ['title' => 'Expired', 'end_date' => now()->subDay()->toDateString()]);

    $this->getJson('/api/v1/listings')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.id', $older)
        ->assertJsonPath('data.1.id', $newer);
});

it('includes a future and a null end_date when not showing all', function () {
    $user = User::factory()->create();
    $categoryId = seedCategoryForList();

    seedListingForList($user->id, $categoryId, ['title' => 'Future', 'end_date' => now()->addDays(10)->toDateString()]);
    seedListingForList($user->id, $categoryId, ['title' => 'NoEnd', 'end_date' => null]);

    $this->getJson('/api/v1/listings')
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

it('returns all listings ordered by price DESC when show_all=true', function () {
    $user = User::factory()->create();
    $categoryId = seedCategoryForList();

    seedListingForList($user->id, $categoryId, ['title' => 'Cheap', 'price' => 100.00]);
    seedListingForList($user->id, $categoryId, ['title' => 'Expensive', 'price' => 300.00, 'moderation_status' => 'pending']);
    seedListingForList($user->id, $categoryId, ['title' => 'Mid', 'price' => 200.00, 'cancelled_at' => now()]);

    $this->getJson('/api/v1/listings?show_all=true')
        ->assertOk()
        ->assertJsonCount(3, 'data')
        ->assertJsonPath('data.0.title', 'Expensive')
        ->assertJsonPath('data.1.title', 'Mid')
        ->assertJsonPath('data.2.title', 'Cheap');
});

it('filters by price range, category, condition and search term', function () {
    $user = User::factory()->create();
    $drivers = seedCategoryForList('Drivers');
    $putters = seedCategoryForList('Putters');

    seedListingForList($user->id, $drivers, ['title' => 'Cheap Driver', 'price' => 50.00, 'condition' => 'New']);
    seedListingForList($user->id, $drivers, ['title' => 'Pricey Driver', 'price' => 500.00, 'condition' => 'Used']);
    seedListingForList($user->id, $putters, ['title' => 'Blade Putter', 'price' => 150.00, 'condition' => 'Used']);

    $this->getJson('/api/v1/listings?min_price=100&max_price=400')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.title', 'Blade Putter');

    $this->getJson("/api/v1/listings?category_id={$putters}")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.title', 'Blade Putter');

    $this->getJson('/api/v1/listings?condition=New')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.title', 'Cheap Driver');

    $this->getJson('/api/v1/listings?q=Blade')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.title', 'Blade Putter');
});

it('paginates with per_page and page', function () {
    $user = User::factory()->create();
    $categoryId = seedCategoryForList();

    foreach (range(1, 3) as $i) {
        seedListingForList($user->id, $categoryId, [
            'title' => "Item {$i}",
            'created_at' => now()->subDays(10 - $i),
        ]);
    }

    $this->getJson('/api/v1/listings?per_page=2')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('meta.per_page', 2)
        ->assertJsonPath('meta.total', 3);

    $this->getJson('/api/v1/listings?per_page=2&page=2')
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

it('shapes the item with split user name, category object and ai_enrichment', function () {
    $user = User::factory()->create(['name' => 'Alice Walker']);
    $categoryId = seedCategoryForList('Wedges');

    seedListingForList($user->id, $categoryId, [
        'title' => 'Sand Wedge',
        'price' => 89.50,
        'condition' => 'Refurbished',
        'ai_enrichment' => json_encode([
            'model_evaluation' => ['summary' => 'Solid wedge', 'features' => ['grooves'], 'confidence' => 0.8],
            'estimated_market_value' => ['value' => 95.0, 'currency' => 'USD'],
        ]),
    ]);

    $this->getJson('/api/v1/listings')
        ->assertOk()
        ->assertJsonPath('data.0.title', 'Sand Wedge')
        ->assertJsonPath('data.0.price', 89.5)
        ->assertJsonPath('data.0.condition', 'Refurbished')
        ->assertJsonPath('data.0.user.first_name', 'Alice')
        ->assertJsonPath('data.0.user.last_name', 'Walker')
        ->assertJsonPath('data.0.category.id', $categoryId)
        ->assertJsonPath('data.0.category.name', 'Wedges')
        ->assertJsonPath('data.0.ai_enrichment.model_evaluation.summary', 'Solid wedge');
});

it('returns ai_enrichment as null when absent', function () {
    $user = User::factory()->create();
    $categoryId = seedCategoryForList();

    seedListingForList($user->id, $categoryId, ['ai_enrichment' => null]);

    $this->getJson('/api/v1/listings')
        ->assertOk()
        ->assertJsonPath('data.0.ai_enrichment', null);
});

it('returns 422 with the normative envelope on an invalid query param', function () {
    $this->getJson('/api/v1/listings?per_page=999')
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'VALIDATION_ERROR')
        ->assertJsonStructure(['error' => ['code', 'message', 'details']]);
});

it('returns 422 when show_all is not a recognized boolean', function () {
    $this->getJson('/api/v1/listings?show_all=banana')
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'VALIDATION_ERROR')
        ->assertJsonStructure(['error' => ['code', 'message', 'details']]);
});

it('accepts show_all=false as a valid boolean query param', function () {
    $user = User::factory()->create();
    $categoryId = seedCategoryForList();
    seedListingForList($user->id, $categoryId, ['title' => 'Visible']);

    $this->getJson('/api/v1/listings?show_all=false')
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

it('is publicly accessible without a token', function () {
    $user = User::factory()->create();
    $categoryId = seedCategoryForList();
    seedListingForList($user->id, $categoryId);

    $this->getJson('/api/v1/listings')->assertOk();
});
