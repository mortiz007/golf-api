<?php

declare(strict_types=1);

use App\Listings\Infrastructure\Eloquent\ListingModel;
use App\Listings\Infrastructure\Jobs\EnrichmentJob;
use App\Listings\Infrastructure\Jobs\ModerationJob;
use App\Models\User;
use App\Support\Telemetry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\CapturingLogger;

uses(RefreshDatabase::class);

function captureJobTelemetry(): CapturingLogger
{
    $logger = new CapturingLogger;

    app()->instance(Telemetry::class, new Telemetry($logger));

    return $logger;
}

function seedListingForJobTelemetry(): int
{
    $userId = User::factory()->create()->id;
    $categoryId = (int) DB::table('categories')->insertGetId([
        'name' => 'Drivers',
        'created_at' => now(),
    ]);

    return (int) ListingModel::create([
        'user_id' => $userId,
        'category_id' => $categoryId,
        'title' => 'Driver Pro',
        'price' => 199.99,
        'condition' => 'Used',
        'description' => 'A great club for sale',
        'moderation_status' => 'pending',
        'ai_enrichment_status' => 'pending',
    ])->id;
}

it('emits job.start and job.outcome on a successful moderation run', function () {
    $logger = captureJobTelemetry();
    $id = seedListingForJobTelemetry();

    ModerationJob::dispatchSync($id);

    $start = $logger->eventsNamed('job.start');
    $outcome = $logger->eventsNamed('job.outcome');

    expect($start)->toHaveCount(1)
        ->and($start[0]['context']['job'])->toBe('moderation')
        ->and($start[0]['context']['listing_id'])->toBe($id)
        ->and($outcome)->toHaveCount(1)
        ->and($outcome[0]['context']['outcome'])->toBe('success')
        ->and($outcome[0]['context'])->toHaveKey('duration_ms');
});

it('emits job.outcome=success for a successful enrichment run', function () {
    $logger = captureJobTelemetry();
    $id = seedListingForJobTelemetry();

    EnrichmentJob::dispatchSync($id);

    $outcome = $logger->eventsNamed('job.outcome');

    expect($outcome)->toHaveCount(1)
        ->and($outcome[0]['context']['job'])->toBe('enrichment')
        ->and($outcome[0]['context']['outcome'])->toBe('success');
});

it('emits job.failed on definitive moderation failure', function () {
    $logger = captureJobTelemetry();
    $id = seedListingForJobTelemetry();

    (new ModerationJob($id))->failed(new RuntimeException('llm timeout'));

    $failed = $logger->eventsNamed('job.failed');

    expect($failed)->toHaveCount(1)
        ->and($failed[0]['level'])->toBe('warning')
        ->and($failed[0]['context']['job'])->toBe('moderation')
        ->and($failed[0]['context']['outcome'])->toBe('failed');
});
