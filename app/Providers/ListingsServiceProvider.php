<?php

declare(strict_types=1);

namespace App\Providers;

use App\Listings\Application\Contracts\DomainEventPublisher;
use App\Listings\Application\Contracts\ListingProcessingDispatcher;
use App\Listings\Domain\Contracts\ListingRepositoryPort;
use App\Listings\Domain\Contracts\LlmPort;
use App\Listings\Infrastructure\Dispatchers\LaravelListingProcessingDispatcher;
use App\Listings\Infrastructure\Events\LaravelDomainEventPublisher;
use App\Listings\Infrastructure\Llm\LlmProviderMock;
use App\Listings\Infrastructure\Repositories\EloquentListingRepository;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the Listings bounded context ports to their concrete adapters
 * (Hexagonal architecture — DESIGN §II "bind Port→Adapter").
 */
final class ListingsServiceProvider extends ServiceProvider
{
    /**
     * Port → Adapter bindings for the Listings context.
     *
     * @var array<class-string, class-string>
     */
    public array $bindings = [
        ListingRepositoryPort::class => EloquentListingRepository::class,
        ListingProcessingDispatcher::class => LaravelListingProcessingDispatcher::class,
        DomainEventPublisher::class => LaravelDomainEventPublisher::class,
    ];

    public function register(): void
    {
        // The LLM adapter is config-driven (config/llm.php) so it can be swapped
        // without touching the domain (DESIGN §V.3).
        $this->app->bind(LlmPort::class, function ($app): LlmPort {
            $provider = (string) config('llm.provider', 'mock');
            $class = config("llm.providers.{$provider}", LlmProviderMock::class);

            return $app->make($class);
        });
    }

    public function boot(): void
    {
        //
    }
}
