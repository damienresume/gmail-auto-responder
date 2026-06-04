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
     *
     * WHY the check order matters (interested → not_interested → meeting → unclear):
     * For a sales platform, "interested" is the highest-value signal — a missed
     * lead costs revenue. Checking it first ensures engagement language is never
     * accidentally consumed by a less valuable category. Not-interested is checked
     * second because automated/opt-out signals are unambiguous and should be
     * filtered before meeting-request, which overlaps with interested language.
     */
    public function classifyEmail(string $subject, string $body, string $fromEmail): ClassificationResult
    {
        $text = strtolower($subject . ' ' . $body);

        // ---------------------------------------------------------------
        // 1. INTERESTED — checked FIRST (highest-value classification).
        //
        // WHY first: In a sales pipeline, every interested lead is
        // potential revenue. Checking this before meeting_request and
        // not_interested prevents emails like "I'm interested, let's
        // schedule a call" from being captured by the less actionable
        // meeting_request bucket. The sales team wants these front and
        // center in the dashboard.
        //
        // WHY two tiers (strong / moderate):
        // Strong signals (explicit intent words) get 95% confidence.
        // Moderate signals (general business language) get 85%. This
        // lets the dashboard sort leads by confidence so the hottest
        // ones are reviewed first.
        // ---------------------------------------------------------------
        $strongInterest = '/\b(interested|partnership|opportunity|proposal|pricing|quote|collaborate|excited|budget|contract|deal|onboard|sign up|get started|go ahead|move forward|next steps|let\'s do it|count me in|ready to)\b/';
        $moderateInterest = '/\b(follow[\s.\-]?up|looking forward|sounds good|sounds great|love to|would like to|learn more|tell me more|more info|more details|keep me posted|stay in touch|circle back|reconnect|touch base)\b/';

        if (preg_match($strongInterest, $text)) {
            return new ClassificationResult(
                classification: 'interested',
                confidence: 0.95,
                reasoning: 'Email contains strong engagement language indicating direct interest or intent to proceed.',
            );
        }

        if (preg_match($moderateInterest, $text)) {
            return new ClassificationResult(
                classification: 'interested',
                confidence: 0.85,
                reasoning: 'Email contains moderate engagement language suggesting continued interest or willingness to explore.',
            );
        }

        // ---------------------------------------------------------------
        // 2. NOT INTERESTED — opt-out / automated / system notifications.
        //
        // WHY before meeting_request: Automated emails sometimes contain
        // scheduling words ("your meeting was cancelled"). Catching opt-out
        // and system signals first prevents false meeting_request hits.
        // ---------------------------------------------------------------
        if (preg_match('/\b(unsubscribe|remove|no longer|opt[\s.\-]?out|not interested|do not reply|noreply|no[\s.\-]?reply|account[\s.\-]?recovered|ssh key|security alert|password reset|verify your|confirm your email|automated message|this is an automated)\b/', $text)) {
            return new ClassificationResult(
                classification: 'not_interested',
                confidence: 0.88,
                reasoning: 'Email appears to be an automated notification, system alert, or opt-out request.',
            );
        }

        // ---------------------------------------------------------------
        // 3. MEETING REQUEST — scheduling-specific language.
        //
        // WHY after interested: "I'm interested, let's schedule a call"
        // is an interested lead first, meeting request second. The sales
        // team cares about the intent (interested) more than the action
        // (scheduling). Pure meeting requests ("Can we reschedule our
        // weekly sync?") with no interest signal land here.
        // ---------------------------------------------------------------
        if (preg_match('/\b(meeting|schedule|call|demo|interview|available|calendar|book a time|reschedule|sync|standup|huddle|catch up)\b/', $text)) {
            return new ClassificationResult(
                classification: 'meeting_request',
                confidence: 0.92,
                reasoning: 'Email contains scheduling language indicating a meeting or call request.',
            );
        }

        // ---------------------------------------------------------------
        // 4. UNCLEAR — no strong signals detected. Flagged for manual
        // review. A draft is still generated so the user has something
        // to work with.
        //
        // WHY same weight as interested (0.85–0.90 confidence):
        // In a sales pipeline, unclear emails often contain genuine leads
        // that use indirect language ("Just saw your product", "Quick
        // question about..."). Giving unclear the same weight as interested
        // ensures these emails get equal dashboard visibility and draft
        // priority. A low confidence (like 0.55) would push unclear
        // threads to the bottom of the review queue, causing the sales
        // team to miss time-sensitive opportunities. The user can always
        // reclassify or discard — but they can't act on emails they
        // never see.
        // ---------------------------------------------------------------
        return new ClassificationResult(
            classification: 'unclear',
            confidence: 0.85,
            reasoning: 'Email intent is ambiguous but may contain a genuine lead. Flagged for manual review with equal priority to interested threads.',
        );
    }

    /**
     * Generate a context-aware, randomized reply based on classification.
     *
     * WHY randomized instead of fixed templates:
     * A real LLM or RAG agent produces unique replies every time, drawing
     * from context, tone, and conversational patterns. Fixed templates
     * make it obvious the system is automated — every "interested" email
     * gets the exact same words, which looks robotic. By combining
     * random greetings, body variations, and closings with content-seeded
     * selection, this stub mimics the natural variety of a real agent
     * while remaining deterministic per-email (same input = same output)
     * for test reproducibility.
     *
     * HOW the seeding works:
     * We hash the subject + body to get a stable integer, then use that
     * as an index into the template arrays. This means the same email
     * always produces the same reply (deterministic for testing), but
     * different emails produce different replies (varied for demos).
     */
    public function generateReply(string $subject, string $body, string $classification): DraftReplyResult
    {
        // Stable seed from content so the same email always picks the same
        // template variants, but different emails get different combinations.
        $seed = crc32($subject . $body);

        $greetings = [
            "Thank you for reaching out about \"{$subject}\".",
            "Thanks for your message regarding \"{$subject}\".",
            "I appreciate you getting in touch about \"{$subject}\".",
            "Great to hear from you about \"{$subject}\".",
            "Thanks for bringing \"{$subject}\" to my attention.",
        ];

        $closings = [
            "Best regards",
            "Looking forward to hearing from you.",
            "Talk soon.",
            "Warm regards",
            "Thanks again — looking forward to connecting.",
        ];

        $greeting = $greetings[abs($seed) % count($greetings)];
        $closing = $closings[abs($seed >> 4) % count($closings)];

        $bodies = match ($classification) {
            'meeting_request' => [
                "I'd love to find a time that works for both of us. I have some availability this week — would any of these slots work?\n\n"
                    . "- Tuesday at 10:00 AM\n- Wednesday at 2:00 PM\n- Thursday at 11:00 AM\n\n"
                    . "Happy to adjust if none of those work.",
                "Absolutely, let's get something on the calendar. I'm flexible this week — could you share a few times that work on your end? "
                    . "I'll send over a calendar invite as soon as we align.",
                "I'd be happy to connect. I typically have openings in the mornings. "
                    . "Would sometime this week or next work for a quick 20-minute chat? Just let me know and I'll block the time.",
                "Sure thing — I'd enjoy the chance to chat. My calendar is fairly open this week. "
                    . "Feel free to suggest a day and time, or I can send a few options your way.",
                "Let's make it happen. I can do most afternoons this week. "
                    . "Would a 30-minute call work? Let me know your preference and I'll get it booked.",
            ],
            'interested' => [
                "I'm glad this resonated with you. I'd love to learn more about your specific needs so I can tailor the next steps. "
                    . "Would you be open to a brief call this week to explore how we can work together?",
                "That's exciting to hear. There's a lot we could explore here. "
                    . "Could you share a bit more about your timeline and what success looks like on your end? "
                    . "That would help me put together something concrete.",
                "I appreciate your interest — this sounds like it could be a great fit. "
                    . "I'd love to dig into the details. Are you free for a quick conversation in the next few days?",
                "Wonderful — I think there's real potential here. "
                    . "To make sure I come prepared, could you let me know what's top of mind for you? "
                    . "I'll put together some thoughts and we can go from there.",
                "This is great to hear. I've been working with a few teams on similar initiatives and would love to share what's been working. "
                    . "Let me know if a short call this week makes sense.",
            ],
            default => [
                "I've read through your message and wanted to make sure it gets the attention it deserves. "
                    . "Could you share a bit more about what you're looking for? That would help me point you in the right direction.",
                "Thanks for writing in. I want to make sure I understand your request correctly — "
                    . "could you provide a few more details about what you have in mind? I'll follow up with a thoughtful response.",
                "I appreciate you reaching out. Your message covers some interesting ground. "
                    . "To give you the most useful response, could you clarify what the ideal outcome looks like for you?",
                "Got it — thanks for the context. I'd like to make sure I'm addressing the right thing. "
                    . "Would you mind elaborating on the key points? I'll circle back with a proper response.",
                "I've noted your message. There are a few different ways I could help here depending on what you're after. "
                    . "Could you let me know which aspect is most important? I'll prioritize accordingly.",
            ],
        };

        $bodyText = $bodies[abs($seed >> 8) % count($bodies)];
        $replyText = "{$greeting}\n\n{$bodyText}\n\n{$closing}";

        return DraftReplyResult::fromLlmResponse(
            response: ['reply' => $replyText],
            originalSubject: $subject,
        );
    }
}
