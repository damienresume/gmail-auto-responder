<?php

namespace App\DTOs;

/**
 * DraftReplyResult
 *
 * PURPOSE:
 * After the LLM generates a reply to an email, this class carries that reply
 * through the system from the LLM adapter, through GenerateDraftJob, into
 * the drafts table and the Gmail API. It holds the subject line, plain text
 * body, and HTML body.
 *
 * WHY a separate DTO from ClassificationResult:
 * These two objects carry completely different data for different purposes.
 * Classification has a category, confidence score, and reasoning.
 * A draft reply has a subject, text body, and HTML body.
 * Combining them into one class would mean half the properties are always
 * null, a sign that the class is doing two unrelated jobs.
 *
 * WHY we store both plain text and HTML:
 * The LLM generates plain text. We convert it to HTML
 * because the Gmail API and the dashboard both need HTML for rendering.
 * We keep the plain text too because:
 *   - It's useful for plain-text email clients.
 *   - It's what the LLM actually wrote (the source of truth).
 *   - Converting HTML back to plain text is unreliable.
 * The storage cost is negligible (a few extra KB per draft).
 *
 * WHY readonly: Same reasoning as ClassificationResult. Once the LLM
 * generates a reply, this snapshot is frozen. If the user wants to edit
 * the reply, they do it in the dashboard, which updates the Draft model
 * in the database, not this transfer object.
 */
readonly class DraftReplyResult
{
    /**
     * @param string $subject  Reply subject line (e.g., "Re: Meeting Thursday")
     * @param string $bodyText Plain text body — the LLM's raw output
     * @param string $bodyHtml HTML body — for the dashboard and Gmail API
     */
    public function __construct(
        public string $subject,
        public string $bodyText,
        public string $bodyHtml,
    ) {}

    /**
     * Create a DraftReplyResult from raw LLM JSON.
     *
     * PURPOSE:
     * The LLM returns a JSON object with the reply text. This factory
     * normalizes that response: trims whitespace, generates HTML from
     * plain text if needed, and adds the "Re:" subject prefix.
     *
     * WHY we generate HTML here:
     *   - LLMs produce unreliable HTML (unclosed tags, broken nesting).
     *   - HTML output costs more tokens than plain text because every
     *     tag (<p>, <br>, </p>) counts as a token.
     *   - Plain text to HTML is a simple, deterministic conversion.
     *     HTML to plain text you lose tables, images, formatting.
     *   So we let the LLM do what it's best at "writing natural language"
     *   and handle the formatting ourselves.
     *
     * @param array<string, mixed> $response        Decoded JSON from the LLM
     * @param string               $originalSubject The original email's subject line
     */
    public static function fromLlmResponse(array $response, string $originalSubject): self
    {
        $bodyText = trim($response['reply'] ?? $response['body'] ?? '');

        // Security: htmlspecialchars() runs BEFORE nl2br().
        // This order matters. htmlspecialchars() escapes characters like < and >
        // so any HTML tags in the LLM's output become harmless text. Then nl2br()
        // converts newlines to <br> tags for display. If we did it the other way
        // around, a malicious email quoted by the LLM could inject scripts into
        // our dashboard (XSS).
        $bodyHtml = !empty($response['reply_html'])
            ? $response['reply_html']
            : '<p>' . nl2br(htmlspecialchars($bodyText, ENT_QUOTES, 'UTF-8')) . '</p>';

        // Don't add "Re:" if the subject already has it. This prevents ugly
        // chains like "Re: Re: Re: Meeting". The check is case-insensitive
        // because some email clients use "RE:" or "re:".
        $subject = str_starts_with(strtolower($originalSubject), 're:')
            ? $originalSubject
            : 'Re: ' . $originalSubject;

        return new self(
            subject: $subject,
            bodyText: $bodyText,
            bodyHtml: $bodyHtml,
        );
    }

    /**
     * Convert to an array that matches the drafts table columns.
     *
     * Used by GenerateDraftJob:
     *   Draft::create($result->toArray() + ['email_thread_id' => $thread->id]);
     */
    public function toArray(): array
    {
        return [
            'body_text' => $this->bodyText,
            'body_html' => $this->bodyHtml,
        ];
    }
}
