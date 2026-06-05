<?php

/**
 * LLM Configuration
 *
 * PURPOSE:
 * Maps .env values to provider-specific settings. The LlmServiceProvider
 * reads this config to decide which LLM implementation to bind into the
 * service container.
 *
 * WHY a dedicated config file instead of putting values in config/services.php:
 * The LLM is a core architectural component with multiple providers, each
 * having different settings (API keys, URLs, models, timeouts). Grouping
 * them in their own file keeps config/services.php clean and makes it
 * obvious where to look when changing LLM settings.
 *
 * HOW to switch providers:
 * Change LLM_PROVIDER in .env to "groq", "ollama", or "stub". No code
 * changes needed, the service provider reads this value and binds the
 * correct implementation automatically.
 */

return [

    // Which LLM provider to use. Read from .env.
    // Options: 'groq' (cloud, fast), 'ollama' (local, private), 'stub' (no LLM)
    'provider' => env('LLM_PROVIDER', 'stub'),

    // The model name passed to the LLM API. Both Groq and Ollama use this.
    // Llama 3.1 8B is the default because it's available on both providers,
    // handles email classification reliably, and fits within Groq's free tier.
    'model' => env('LLM_MODEL', 'llama-3.1-8b'),

    // Provider-specific settings.
    'groq' => [
        'api_key' => env('GROQ_API_KEY', ''),
        'api_url' => env('GROQ_API_URL', 'https://api.groq.com/openai/v1'),
    ],

    'ollama' => [
        // Points to the Ollama Docker container by service name.
        // Docker's internal DNS resolves "ollama" to the container's IP.
        'host' => env('OLLAMA_HOST', 'http://ollama:11434'),
    ],
];
