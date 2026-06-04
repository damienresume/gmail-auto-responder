<?php

namespace App\Services\Llm;

use App\DTOs\ClassificationResult;
use App\DTOs\DraftReplyResult;

/**
 * StubLlmService
 *
 * PURPOSE:
 * A fake LLM provider that returns instant, deterministic responses without
 * calling any external API or running any model. Set LLM_PROVIDER=stub in
 * .env to use it.
 *
 * WHY this exists:
 *   - Code review: A Developer can clone the repo, run docker compose up,
 *     and see the full pipeline working end-to-end without signing up for
 *     Groq or downloading an 8GB Ollama model.
 *
 *   - Testing: Unit and integration tests use this to verify queue jobs,
 *     controllers, and the classification pipeline without network calls
 *     or model inference. Tests stay fast and deterministic.
 *
 *   - Local development: Developers can work on the dashboard, API, and
 *     queue logic without waiting for LLM responses (Ollama on CPU takes
 *     5-15 seconds per call).
 *
 * HOW it works:
 *   - classifyEmail() returns "interested" for every email with 0.85 confidence.
 *     This ensures GenerateDraftJob always fires, so the full pipeline
 *     (fetch -> classify -> draft) can be tested end-to-end.
 *
 *   - generateReply() returns a canned professional reply. The text is static
 *     so test assertions can match exact output.
 */
class StubLlmService implements LlmServiceInterface
{
    /**
     * Always classifies as "interested" with 85% confidence.
     *
     * WHY "interested" and not random:
     * Deterministic output makes debugging straightforward. If every email
     * is classified as "interested", every email gets a draft, which means
     * every part of the pipeline executes. Random output would make tests
     * inconsistent and make it hard to reproduce bugs.
     */
    public function classifyEmail(string $subject, string $body, string $fromEmail): ClassificationResult
    {
        return new ClassificationResult(
            classification: 'interested',
            confidence: 0.85,
            reasoning: 'Stub provider: automatically classified as interested for development and testing.',
        );
    }

    /**
     * Returns a automated professional reply.
     *
     * WHY a realistic reply instead of "test reply":
     * The dashboard renders this text. A realistic reply lets you 
     * see what the UI will actually look like in production,
     * including formatting, line breaks, and length.
     */
    public function generateReply(string $subject, string $body, string $classification): DraftReplyResult
    {
        $replyText = "Thank you for your email regarding \"{$subject}\".\n\n"
            . "I've reviewed your message and would like to follow up. "
            . "Could we schedule a brief call to discuss this further?\n\n"
            . "Looking forward to hearing from you.\n\n"
            . "Best regards";

        return DraftReplyResult::fromLlmResponse(
            response: ['reply' => $replyText],
            originalSubject: $subject,
        );
    }
}
