<?php

declare(strict_types=1);

namespace App\Listings\Infrastructure\Events;

use App\Listings\Application\Contracts\DomainEventPublisher;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionResolverInterface;

/**
 * Laravel adapter for the DomainEventPublisher port (S1-08).
 *
 * Publishes domain events in-process with true after-commit semantics
 * (DESIGN §IV): the dispatch is registered as a database after-commit callback.
 * When a transaction is active it runs only after the outermost commit; with no
 * active transaction it runs immediately. The AuditLog ShouldQueue listener then
 * enqueues itself onto the `database` queue.
 */
final class LaravelDomainEventPublisher implements DomainEventPublisher
{
    public function __construct(
        private readonly Dispatcher $events,
        private readonly ConnectionResolverInterface $connections,
    ) {}

    public function publishAfterCommit(object $event): void
    {
        /** @var Connection $connection */
        $connection = $this->connections->connection();

        $connection->afterCommit(function () use ($event): void {
            $this->events->dispatch($event);
        });
    }
}
