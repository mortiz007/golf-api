<?php

declare(strict_types=1);

namespace App\Listings\Application\Contracts;

/**
 * Outbound port to publish domain events after the DB transaction commits.
 *
 * The Laravel-backed adapter (bound near S1-13) uses the framework dispatcher's
 * dispatchAfterCommit(...) so listeners run only after a successful commit
 * (DESIGN §IV).
 */
interface DomainEventPublisher
{
    public function publishAfterCommit(object $event): void;
}
