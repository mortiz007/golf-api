<?php

declare(strict_types=1);

namespace App\Providers;

use App\Listings\Application\Contracts\DomainEventPublisher;
use App\Listings\Application\Contracts\ListingProcessingDispatcher;
use App\Listings\Application\Contracts\ListingQueryPort;
use App\Listings\Domain\Contracts\ListingRepositoryPort;
use App\Listings\Domain\Contracts\LlmPort;
use App\Listings\Infrastructure\Dispatchers\LaravelListingProcessingDispatcher;
use App\Listings\Infrastructure\Events\LaravelDomainEventPublisher;
use App\Listings\Infrastructure\Llm\LlmProviderMock;
use App\Listings\Infrastructure\Llm\OllamaLlmProvider;
use App\Listings\Infrastructure\Llm\OllamaPromptBuilder;
use App\Listings\Infrastructure\Llm\OllamaResponseMapper;
use App\Listings\Infrastructure\Repositories\EloquentListingQueryRepository;
use App\Listings\Infrastructure\Repositories\EloquentListingRepository;
use App\Support\Telemetry;
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
        ListingQueryPort::class => EloquentListingQueryRepository::class,
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

        // OllamaLlmProvider needs its settings injected from config; the generic
        // LlmPort binding above resolves this explicit binding via make(). Its
        // prompt builder and response mapper collaborators are autowired.
        $this->app->bind(OllamaLlmProvider::class, fn ($app): OllamaLlmProvider => new OllamaLlmProvider(
            baseUrl: (string) config('llm.ollama.base_url'),
            model: (string) config('llm.ollama.model'),
            timeout: (int) config('llm.ollama.timeout'),
            temperature: (float) config('llm.ollama.temperature'),
            keepAlive: (string) config('llm.ollama.keep_alive'),
            prompts: $app->make(OllamaPromptBuilder::class),
            mapper: $app->make(OllamaResponseMapper::class),
            telemetry: $app->make(Telemetry::class),
        ));
    }

    public function boot(): void
    {
        //
    }
}
