<?php

namespace App\Services\Llm;

use App\DTOs\ClassificationResult;
use App\DTOs\DraftReplyResult;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * OllamaLlmService
 *
 * PURPOSE:
 * Runs LLM inference locally using Ollama, which is included as a Docker
 * service in the project. No API key needed, no data leaves your machine.
 * Set LLM_PROVIDER=ollama in .env to use it.
 *
 * WHY Ollama as an alternative to Groq:
 *   - Privacy: Email content never leaves your infrastructure. For companies
 *     with data residency requirements or sensitive communications, this is
 *     the only option that keeps everything local.
 *
 *   - No signup: Works immediately after pulling the model. No API key,
 *     no account creation, no billing setup.
 *
 *   - Offline capable: Works without internet access. Useful for development
 *     on planes, in restricted networks, or in isolated environments.
 *
 * HOW it works:
 *   Ollama exposes an OpenAI compatible API at http://ollama:11434.
 *   The request format is nearly identical to GroqLlmService, but with
 *   a longer timeout to account for CPU based inference speed. The prompts
 *   and response parsing are shared via the same DTO factories.
 *
 * SECURITY:
 *   All inference happens inside the Docker network. Email content never
 *   leaves the machine. The Ollama container has no internet access by
 *   default (it only needs it once to pull the model).
 */
class OllamaLlmService implements LlmServiceInterface
{
    public function __construct(
        private readonly string $host,
        private readonly string $model,
    ) {}

    public function classifyEmail(string $subject, string $body, string $fromEmail): ClassificationResult
    {
        $systemPrompt = <<<PROMPT
        You are an email classification assistant. Analyze the email and respond with ONLY a JSON object.

        Classify into exactly one category:
        - "interested": The sender wants to engage, buy, partner, or learn more.
        - "not_interested": The sender is declining, unsubscribing, or not relevant.
        - "meeting_request": The sender is requesting or proposing a meeting, call, or demo.
        - "unclear": The intent is ambiguous or doesn't fit the other categories.

        Respond with this exact JSON structure, nothing else:
        {"classification": "category_here", "confidence": 0.0, "reasoning": "brief explanation"}
        PROMPT;

        $userPrompt = "From: {$fromEmail}\nSubject: {$subject}\n\nBody:\n{$body}";

        $response = $this->callApi($systemPrompt, $userPrompt);

        return ClassificationResult::fromLlmResponse($response);
    }

    public function generateReply(string $subject, string $body, string $classification): DraftReplyResult
    {
        $systemPrompt = <<<PROMPT
        You are a professional email assistant. Write a reply to the email below.

        The email has been classified as: {$classification}

        Guidelines:
        - Be professional, concise, and friendly.
        - For "interested": express enthusiasm and suggest next steps.
        - For "meeting_request": confirm interest and propose availability.
        - For "unclear": politely ask for clarification on their intent.
        - Do NOT fabricate facts, names, or commitments the user hasn't made.
        - Keep the reply under 150 words.

        Respond with ONLY a JSON object:
        {"reply": "your reply text here"}
        PROMPT;

        $userPrompt = "Subject: {$subject}\n\nOriginal email:\n{$body}";

        $response = $this->callApi($systemPrompt, $userPrompt);

        return DraftReplyResult::fromLlmResponse($response, $subject);
    }

    /**
     * Send a request to Ollama's local API.
     *
     * WHY 120-second timeout (vs 30 for Groq):
     * Ollama on CPU processes tokens much slower than Groq's dedicated hardware.
     * A classification call can take 5-15 seconds on a modern CPU, and reply
     * generation (more tokens) can take 15-30 seconds. The 120-second timeout
     * handles worst-case scenarios (cold model load + slow CPU) without
     * prematurely killing a valid request.
     *
     * WHY "format": "json" in the request:
     * Ollama supports a native JSON mode that constrains the model's output
     * to valid JSON. This is more reliable than relying on prompt instructions
     * alone, especially with smaller models that sometimes ignore formatting
     * requests.
     */
    private function callApi(string $systemPrompt, string $userPrompt): array
    {
        try {
            $response = Http::timeout(120)
                ->post($this->host . '/api/chat', [
                    'model' => $this->model,
                    'messages' => [
                        ['role' => 'system', 'content' => $systemPrompt],
                        ['role' => 'user', 'content' => $userPrompt],
                    ],
                    'format' => 'json',
                    'stream' => false,
                    // WHY stream: false Ollama streams by default, sending
                    // one token at a time via chunked transfer encoding.
                    // We don't need real-time streaming in a queue job, we
                    // just want the complete response. Setting this to false
                    // makes Ollama buffer the full response and return it as
                    // a single JSON object, which is simpler to parse.
                    'options' => [
                        'temperature' => 0.3,
                    ],
                ]);

            if (!$response->successful()) {
                Log::error('Ollama API error', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return $this->fallbackResponse();
            }

            // Ollama returns the response in a different structure than
            // the OpenAI format: { "message": { "content": "..." } }
            $content = $response->json('message.content', '');

            return $this->parseJsonResponse($content);
        } catch (ConnectionException $e) {
            // WHY we catch this specifically: If Ollama isn't running (user
            // didn't start it with docker compose --profile tools), the
            // connection will be refused. We log and degrade gracefully
            // rather than crashing the queue job.
            Log::error('Ollama connection failed. Is the Ollama container running?', [
                'host' => $this->host,
                'error' => $e->getMessage(),
            ]);

            return $this->fallbackResponse();
        }
    }

    /**
     * Parse Ollama's response content into a JSON array.
     *
     * WHY separate from GroqLlmService's parser:
     * Although the parsing logic is similar, keeping each provider
     * self-contained means a change to one provider's parsing (e.g.,
     * handling a new Ollama response format) doesn't risk breaking
     * the other provider. Each provider is independently deployable
     * and testable.
     */
    private function parseJsonResponse(string $content): array
    {
        $content = trim($content);

        // Strip markdown fences if the model wraps its response.
        $content = preg_replace('/^```(?:json)?\s*/i', '', $content);
        $content = preg_replace('/\s*```$/i', '', $content);
        $content = trim($content);

        if (preg_match('/\{[^{}]*\}/s', $content, $matches)) {
            $content = $matches[0];
        }

        $decoded = json_decode($content, true);

        if (!is_array($decoded)) {
            Log::warning('Ollama returned unparseable response', ['raw' => $content]);
            return $this->fallbackResponse();
        }

        return $decoded;
    }

    private function fallbackResponse(): array
    {
        return [
            'classification' => 'unclear',
            'confidence' => 0.0,
            'reasoning' => 'Ollama service unavailable. Defaulting to unclear for manual review.',
            'reply' => 'Thank you for your email. I will review and get back to you shortly.',
        ];
    }
}
