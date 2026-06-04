<?php

namespace App\Services\Llm;

use App\DTOs\ClassificationResult;
use App\DTOs\DraftReplyResult;

/**
 * StubLlmService
 *
 * PURPOSE:
 * A keyword-based LLM stub that returns realistic, varied responses without
 * calling any external API. Set LLM_PROVIDER=stub in .env to use it.
 *
 * WHY this exists:
 *   - Code review: A developer can clone the repo, run docker compose up,
 *     and see the full pipeline working with realistic data. No Groq signup
 *     or 8GB Ollama model download needed.
 *   - Testing: Unit and integration tests get fast, deterministic results.
 *   - Demo: The dashboard shows varied classifications and contextual drafts
 *     instead of identical "interested" on every thread.
 *
 * HOW it works:
 *   - classifyEmail() scans the subject and body for keywords to determine
 *     classification. "meeting", "call", "schedule" -> meeting_request.
 *     "unsubscribe", "remove", "no longer" -> not_interested. Etc.
 *     This produces realistic demo data that shows all four categories.
 *   - generateReply() tailors the response based on the classification,
 *     referencing the actual subject line for context.
 */
class StubLlmService implements LlmServiceInterface
{
    /**
     * Classify based on keyword analysis of the email content.
     *
     * WHY keyword-based instead of random:
     * Random would produce inconsistent results across page reloads.
     * Keyword-based is deterministic (same email always gets the same
     * classification) and produces realistic variety in the dashboard.
     */
    public function classifyEmail(string $subject, string $body, string $fromEmail): ClassificationResult
    {
        $text = strtolower($subject . ' ' . $body);

        // Meeting request: scheduling language
        if (preg_match('/\b(meeting|schedule|call|demo|interview|available|calendar|book a time)\b/', $text)) {
            return new ClassificationResult(
                classification: 'meeting_request',
                confidence: 0.92,
                reasoning: 'Email contains scheduling language indicating a meeting or call request.',
            );
        }

        // Not interested: opt-out or automated notifications
        if (preg_match('/\b(unsubscribe|remove|no longer|opt.out|not interested|do not reply|noreply|account.recovered|ssh key|security alert)\b/', $text)) {
            return new ClassificationResult(
                classification: 'not_interested',
                confidence: 0.88,
                reasoning: 'Email appears to be an automated notification or opt-out request.',
            );
        }

        // Interested: engagement language
        if (preg_match('/\b(interested|partnership|opportunity|follow.up|proposal|pricing|quote|collaborate|excited|love to)\b/', $text)) {
            return new ClassificationResult(
                classification: 'interested',
                confidence: 0.91,
                reasoning: 'Email contains engagement language suggesting interest in collaboration or business.',
            );
        }

        // Default: unclear
        return new ClassificationResult(
            classification: 'unclear',
            confidence: 0.55,
            reasoning: 'Email intent is ambiguous. Flagged for manual review.',
        );
    }

    /**
     * Generate a context-aware reply based on the classification.
     *
     * WHY different replies per classification:
     * The dashboard should show that the system tailors responses.
     * A meeting request gets a scheduling reply, an interested email
     * gets an enthusiasm reply. This demonstrates the LLM adapter's
     * role even without a real model running.
     */
    public function generateReply(string $subject, string $body, string $classification): DraftReplyResult
    {
        $replyText = match ($classification) {
            'meeting_request' => "Thank you for reaching out about \"{$subject}\".\n\n"
                . "I'd be happy to schedule a time to connect. I'm generally available "
                . "Monday through Thursday. Would any of the following work for you?\n\n"
                . "- Tomorrow at 10:00 AM EST\n"
                . "- Wednesday at 2:00 PM EST\n"
                . "- Thursday at 11:00 AM EST\n\n"
                . "Please let me know what works best, and I'll send over a calendar invite.\n\n"
                . "Best regards",

            'interested' => "Thank you for your email regarding \"{$subject}\".\n\n"
                . "I appreciate your interest and would love to explore this further. "
                . "Could you share a bit more about what you have in mind? "
                . "I'd be happy to set up a call to discuss next steps.\n\n"
                . "Looking forward to hearing from you.\n\n"
                . "Best regards",

            default => "Thank you for your email regarding \"{$subject}\".\n\n"
                . "I've received your message and wanted to acknowledge it. "
                . "Could you provide a bit more context about what you're looking for? "
                . "That would help me give you a more helpful response.\n\n"
                . "Thanks,\nBest regards",
        };

        return DraftReplyResult::fromLlmResponse(
            response: ['reply' => $replyText],
            originalSubject: $subject,
        );
    }
}
