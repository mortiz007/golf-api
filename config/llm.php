<?php

declare(strict_types=1);

use App\Listings\Infrastructure\Llm\LlmProviderMock;

return [

    /*
    |--------------------------------------------------------------------------
    | LLM Provider
    |--------------------------------------------------------------------------
    |
    | Selects which adapter is bound to App\Listings\Domain\Contracts\LlmPort.
    | Defaults to the in-process mock (DESIGN §V.3 / SPECS #12: no external
    | source). Swap the provider here without touching the domain.
    |
    */

    'provider' => env('LLM_PROVIDER', 'mock'),

    'providers' => [
        'mock' => LlmProviderMock::class,
    ],

];
