<?php

namespace App\Providers;

use App\Services\Llm\GroqLlmService;
use App\Services\Llm\LlmServiceInterface;
use App\Services\Llm\OllamaLlmService;
use App\Services\Llm\StubLlmService;
use Illuminate\Support\ServiceProvider;

/**
 * LlmServiceProvider
 *
 * PURPOSE:
 * Reads LLM_PROVIDER from .env and binds the matching implementation to
 * the LlmServiceInterface in Laravel's service container. This is the
 * wiring that makes the Adapter Pattern work, queue jobs type-hint the
 * interface, and the container injects the correct provider automatically.
 *
 * WHY a service provider instead of binding in AppServiceProvider:
 * AppServiceProvider is a catch-all that grows messy in large projects.
 * A dedicated provider keeps LLM wiring isolated. It can be registered
 * or deregistered independently, and it's immediately clear where the
 * LLM binding lives when debugging.
 *
 * HOW it works:
 *   1. Laravel boots, registers this provider (via config/app.php).
 *   2. register() reads config('llm.provider'), which comes from .env.
 *   3. Based on the value ('groq', 'ollama', or 'stub'), it binds the
 *      corresponding class to LlmServiceInterface as a singleton.
 *   4. When a queue job or controller type-hints LlmServiceInterface,
 *      Laravel's container resolves it to the bound implementation.
 *
 * WHY singleton:
 * The LLM service holds config values (API key, URL, model) but no
 * request specific state. Creating a new instance on every injection
 * would waste memory re-reading the same config. A singleton creates
 * one instance per request lifecycle and reuses it.
 */
class LlmServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(LlmServiceInterface::class, function ($app) {
            $provider = config('llm.provider', 'stub');
            $model = config('llm.model', 'llama-3.1-8b');

            return match ($provider) {
                'groq' => new GroqLlmService(
                    apiKey: config('llm.groq.api_key', ''),
                    apiUrl: config('llm.groq.api_url', 'https://api.groq.com/openai/v1'),
                    model: $model,
                ),

                'ollama' => new OllamaLlmService(
                    host: config('llm.ollama.host', 'http://ollama:11434'),
                    model: $model,
                ),

                // WHY stub is the default: If LLM_PROVIDER is missing or
                // misspelled in .env, the app should still work. Stub returns
                // deterministic responses so the pipeline doesn't crash.
                // A missing config should degrade gracefully, not fatally.
                default => new StubLlmService(),
            };
        });
    }
}
