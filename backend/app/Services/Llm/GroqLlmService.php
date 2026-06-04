<?php

namespace App\Services\Llm;

use App\DTOs\ClassificationResult;
use App\DTOs\DraftReplyResult;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * GroqLlmService
 *
 * PURPOSE:
 * Sends email content to Groq's cloud API for classification and reply
 * generation. Groq is the default LLM provider because it offers the fastest
 * inference speed (~0.3s per call) with a free tier (30 requests/minute on
 * Llama 3.1 8B). Set LLM_PROVIDER=groq in .env to use it.
 *
 * WHY Groq over other cloud providers:
 *   - Speed: Groq uses custom LPU hardware built specifically for LLM
 *     inference. It runs Llama 3.1 8B at ~800 tokens/second, which is
 *     10-50x faster than GPU based providers. In a queue-based system,
 *     faster inference means shorter queue backlogs.
 *
 *   - Cost: The free tier supports 30 requests/minute, which handles
 *     ~43,000 emails/day.
 *
 *   - API compatibility: Groq uses the OpenAI chat completions format,
 *     so the HTTP request structure is widely understood and documented.
 *
 * HOW it works:
 *   - Each method builds a prompt with system instructions and the email
 *     content, sends it to Groq's chat completions endpoint, parses the
 *     JSON response, and returns a validated DTO.
 *
 *   - The system prompt requests JSON output with specific field names.
 *     This is more reliable than asking the LLM for free-form text and
 *     trying to parse it afterward.
 *
 * SECURITY:
 *   - The API key is read from config (which reads from .env). It is never
 *     hardcoded, logged, or included in error messages.
 *
 *   - Email content sent to Groq's API leaves your infrastructure. If this
 *     is a concern, use Ollama (local inference) or Stub (no LLM) instead.
 */
class GroqLlmService implements LlmServiceInterface
{
    /**
     * WHY constructor injection for config values:
     * Reading config once at construction (not on every method call) avoids
     * repeated config lookups. The values are also explicit dependencies,
     * if the config is missing, the error happens at construction time (early),
     * not deep inside a queue job (late and harder to debug).
     */
    public function __construct(
        private readonly string $apiKey,
        private readonly string $apiUrl,
        private readonly string $model,
    ) {}

    public function classifyEmail(string $subject, string $body, string $fromEmail): ClassificationResult
    {
        // WHY a structured system prompt:
        // The system prompt tells the LLM exactly what JSON shape to return.
        // Without this, the LLM might return free-form text like "I think this
        // is an interested email because..." which we'd have to parse with
        // regex which is fragile and error-prone.
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
        // WHY classification is included in the prompt:
        // Telling the LLM the classification helps it calibrate tone.
        // An "interested" reply should be enthusiastic and action-oriented.
        // A "meeting_request" reply should confirm availability.
        // An "unclear" reply should be cautious and ask for clarification.
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
     * Send a request to Groq's chat completions API.
     *
     * PURPOSE:
     * Shared HTTP logic for both classifyEmail() and generateReply().
     * Handles the API call, timeout, error handling, and JSON parsing
     * in one place so each public method only needs to build its prompt.
     *
     * WHY 30-second timeout:
     * Groq typically responds in <1 second. A 30-second timeout is generous
     * enough to handle rare slowdowns but short enough that a hung connection
     * doesn't block a Horizon worker indefinitely. If the timeout fires, the
     * queue job will retry with exponential backoff.
     *
     * WHY json_decode with try/catch:
     * LLMs sometimes return JSON wrapped in markdown code fences (```json...```)
     * or prefixed with explanatory text. We strip common wrappers and attempt
     * to decode. If it still fails, we return a fallback response rather than
     * crashing the queue job, the DTO factory handles invalid values gracefully.
     *
     * @return array<string, mixed> Decoded JSON from the LLM's response
     */
    private function callApi(string $systemPrompt, string $userPrompt): array
    {
        try {
            $response = Http::withHeaders([
                    'Authorization' => 'Bearer ' . $this->apiKey,
                    'Content-Type' => 'application/json',
                ])
                ->timeout(30)
                ->post($this->apiUrl . '/chat/completions', [
                    'model' => $this->model,
                    'messages' => [
                        ['role' => 'system', 'content' => $systemPrompt],
                        ['role' => 'user', 'content' => $userPrompt],
                    ],
                    'temperature' => 0.3,
                    // WHY temperature 0.3: Low temperature produces more
                    // deterministic, focused output. Classification needs
                    // consistency (same email → same category every time).
                    // Higher temperatures (0.7+) add creativity, which is
                    // undesirable for a classification task.
                    'max_tokens' => 500,
                ]);

            if (!$response->successful()) {
                Log::error('Groq API error', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return $this->fallbackResponse();
            }

            $content = $response->json('choices.0.message.content', '');

            return $this->parseJsonResponse($content);
        } catch (ConnectionException $e) {
            Log::error('Groq API connection failed', ['error' => $e->getMessage()]);

            return $this->fallbackResponse();
        }
    }

    /**
     * Parse the LLM's response text into a JSON array.
     *
     * WHY this extra parsing step:
     * LLMs don't always return clean JSON. Common issues:
     *   - Wrapped in markdown: ```json\n{...}\n```
     *   - Prefixed with text: "Here is the classification:\n{...}"
     *   - Trailing commas: {"classification": "interested",}
     * This method strips the most common wrappers and attempts to decode.
     */
    private function parseJsonResponse(string $content): array
    {
        // Strip markdown code fences if present.
        $content = preg_replace('/^```(?:json)?\s*/i', '', $content);
        $content = preg_replace('/\s*```$/i', '', $content);
        $content = trim($content);

        // Extract the first JSON object if there's surrounding text.
        if (preg_match('/\{[^{}]*\}/s', $content, $matches)) {
            $content = $matches[0];
        }

        $decoded = json_decode($content, true);

        if (!is_array($decoded)) {
            Log::warning('Groq returned unparseable response', ['raw' => $content]);
            return $this->fallbackResponse();
        }

        return $decoded;
    }

    /**
     * Fallback response when the API fails or returns garbage.
     *
     * WHY "unclear" instead of throwing an exception:
     * If the LLM is down, we don't want to lose the email. Returning "unclear"
     * means the email still gets a draft (which the user can handle manually).
     * The queue job can also retry, the fallback is a graceful degradation,
     * not a permanent result.
     */
    private function fallbackResponse(): array
    {
        return [
            'classification' => 'unclear',
            'confidence' => 0.0,
            'reasoning' => 'LLM service unavailable. Defaulting to unclear for manual review.',
            'reply' => 'Thank you for your email. I will review and get back to you shortly.',
        ];
    }
}
