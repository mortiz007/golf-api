<?php

declare(strict_types=1);

namespace App\Providers;

use App\AuditLog\Domain\Contracts\AuditLogRepositoryPort;
use App\AuditLog\Infrastructure\Listeners\RecordAuditLogListener;
use App\AuditLog\Infrastructure\Repositories\EloquentAuditLogRepository;
use App\Listings\Domain\Events\ListingCreated;
use App\Listings\Domain\Events\ListingDeleted;
use App\Listings\Domain\Events\ListingUpdated;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the AuditLog bounded context: binds the repository port to its Eloquent
 * adapter and subscribes the queued listener to the three audited Listings
 * domain events (DESIGN §IV — decision 2-B).
 */
final class AuditLogServiceProvider extends ServiceProvider
{
    /**
     * Port → Adapter bindings for the AuditLog context.
     *
     * @var array<class-string, class-string>
     */
    public array $bindings = [
        AuditLogRepositoryPort::class => EloquentAuditLogRepository::class,
    ];

    public function boot(): void
    {
        Event::listen(ListingCreated::class, RecordAuditLogListener::class);
        Event::listen(ListingUpdated::class, RecordAuditLogListener::class);
        Event::listen(ListingDeleted::class, RecordAuditLogListener::class);
    }
}
