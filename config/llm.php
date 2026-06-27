<?php

declare(strict_types=1);

use App\Listings\Infrastructure\Llm\LlmProviderMock;
use App\Listings\Infrastructure\Llm\OllamaLlmProvider;

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
        'ollama' => OllamaLlmProvider::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Ollama Adapter
    |--------------------------------------------------------------------------
    |
    | Settings for the OllamaLlmProvider, which talks to a local Ollama server
    | (POST /api/chat) running the configured model. Consumed by the explicit
    | binding in ListingsServiceProvider.
    |
    */

    'ollama' => [
        'base_url' => env('OLLAMA_BASE_URL', 'http://localhost:11434'),
        'model' => env('OLLAMA_MODEL', 'qwen2.5-coder:7b'),
        'timeout' => (int) env('OLLAMA_TIMEOUT', 60),
        'temperature' => (float) env('OLLAMA_TEMPERATURE', 0.1),
        'keep_alive' => env('OLLAMA_KEEP_ALIVE', '5m'),
    ],

];
