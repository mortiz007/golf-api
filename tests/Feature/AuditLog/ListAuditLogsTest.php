<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * Inserts an audit log row directly (read-side fixture; bypasses the listener).
 */
function seedAuditLog(int $userId, int $listingId, string $action, string $createdAt, string $message = 'Audited fact'): void
{
    DB::table('listing_audit_logs')->insert([
        'user_id' => $userId,
        'listing_id' => $listingId,
        'action' => $action,
        'message' => $message,
        'metadata' => json_encode(['id' => $listingId, 'title' => 'Driver X']),
        'event_id' => (string) Str::uuid(),
        'created_at' => $createdAt,
    ]);
}

it('returns only the authenticated user\'s audit logs', function () {
    $userA = User::factory()->create();
    $userB = User::factory()->create();

    seedAuditLog($userA->id, 1, 'created', '2026-06-20 10:00:00');
    seedAuditLog($userA->id, 2, 'updated', '2026-06-21 10:00:00');
    seedAuditLog($userB->id, 99, 'created', '2026-06-22 10:00:00', 'B private fact');

    Sanctum::actingAs($userA);

    $response = $this->getJson('/api/v1/audit-logs');

    $response->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('meta.total', 2);

    foreach ($response->json('data') as $item) {
        expect($item['metadata']['id'])->not->toBe(99);
        expect($item['message'])->not->toBe('B private fact');
    }
});

it('orders audit logs by created_at descending', function () {
    $user = User::factory()->create();

    seedAuditLog($user->id, 1, 'created', '2026-06-20 10:00:00', 'oldest');
    seedAuditLog($user->id, 2, 'updated', '2026-06-22 10:00:00', 'newest');
    seedAuditLog($user->id, 3, 'deleted', '2026-06-21 10:00:00', 'middle');

    Sanctum::actingAs($user);

    $response = $this->getJson('/api/v1/audit-logs')->assertOk();

    expect($response->json('data.0.message'))->toBe('newest')
        ->and($response->json('data.1.message'))->toBe('middle')
        ->and($response->json('data.2.message'))->toBe('oldest');
});

it('paginates audit logs at 20 per page', function () {
    $user = User::factory()->create();

    for ($i = 1; $i <= 25; $i++) {
        seedAuditLog($user->id, $i, 'created', Carbon::parse('2026-06-20 10:00:00')->addMinutes($i)->toDateTimeString());
    }

    Sanctum::actingAs($user);

    $this->getJson('/api/v1/audit-logs')
        ->assertOk()
        ->assertJsonCount(20, 'data')
        ->assertJsonPath('meta.total', 25)
        ->assertJsonPath('meta.last_page', 2)
        ->assertJsonPath('meta.current_page', 1);

    $this->getJson('/api/v1/audit-logs?page=2')
        ->assertOk()
        ->assertJsonCount(5, 'data')
        ->assertJsonPath('meta.current_page', 2);
});

it('exposes only the contract fields for each entry', function () {
    $user = User::factory()->create();
    seedAuditLog($user->id, 7, 'created', '2026-06-20 10:00:00');

    Sanctum::actingAs($user);

    $this->getJson('/api/v1/audit-logs')
        ->assertOk()
        ->assertJsonStructure([
            'data' => [
                ['id', 'action', 'message', 'metadata', 'created_at'],
            ],
            'links',
            'meta',
        ]);
});

it('returns 401 when no token is provided', function () {
    $this->getJson('/api/v1/audit-logs')->assertUnauthorized();
});
