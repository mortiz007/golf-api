<?php

declare(strict_types=1);

use App\AuditLog\Application\Commands\RecordAuditLogCommand;
use App\AuditLog\Application\UseCases\RecordAuditLogUseCase;
use App\AuditLog\Domain\Contracts\AuditLogRepositoryPort;
use App\AuditLog\Domain\Entities\AuditLogEntry;
use App\AuditLog\Domain\ValueObjects\AuditAction;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * In-memory repository fake capturing saved entries (no DB).
 */
function auditRepositoryFake(): AuditLogRepositoryPort
{
    return new class implements AuditLogRepositoryPort
    {
        /** @var array<int, AuditLogEntry> */
        public array $saved = [];

        public function save(AuditLogEntry $entry): void
        {
            $this->saved[] = $entry;
        }

        public function findByUser(int $userId, int $page): LengthAwarePaginator
        {
            throw new RuntimeException('Not used in this test.');
        }
    };
}

it('records an audit entry building the legible message', function () {
    $repo = auditRepositoryFake();
    $useCase = new RecordAuditLogUseCase($repo);

    $command = new RecordAuditLogCommand(
        eventId: '550e8400-e29b-41d4-a716-446655440000',
        userId: 45,
        action: 'created',
        listingId: 123,
        listingTitle: 'Driver X',
        snapshot: ['id' => 123, 'title' => 'Driver X', 'price' => 199.99],
    );

    $useCase->execute($command);

    expect($repo->saved)->toHaveCount(1);

    $entry = $repo->saved[0];
    expect((string) $entry->eventId())->toBe('550e8400-e29b-41d4-a716-446655440000')
        ->and($entry->userId())->toBe(45)
        ->and($entry->action())->toBe(AuditAction::Created)
        ->and((string) $entry->message())->toBe("Created listing 'Driver X' (id: 123) by user 45")
        ->and($entry->metadata())->toBe(['id' => 123, 'title' => 'Driver X', 'price' => 199.99]);
});

it('maps each action to its AuditAction', function (string $action, AuditAction $expected, string $verb) {
    $repo = auditRepositoryFake();
    $useCase = new RecordAuditLogUseCase($repo);

    $useCase->execute(new RecordAuditLogCommand(
        eventId: '550e8400-e29b-41d4-a716-446655440000',
        userId: 7,
        action: $action,
        listingId: 9,
        listingTitle: 'Putter Y',
        snapshot: [],
    ));

    $entry = $repo->saved[0];
    expect($entry->action())->toBe($expected)
        ->and((string) $entry->message())->toBe("{$verb} listing 'Putter Y' (id: 9) by user 7");
})->with([
    ['created', AuditAction::Created, 'Created'],
    ['updated', AuditAction::Updated, 'Updated'],
    ['deleted', AuditAction::Deleted, 'Deleted'],
]);
