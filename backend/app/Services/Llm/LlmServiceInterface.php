<?php

namespace App\Services\Llm;

use App\DTOs\ClassificationResult;
use App\DTOs\DraftReplyResult;

/**
 * LlmServiceInterface
 *
 * PURPOSE:
 * Defines the contract that every LLM provider (Groq, Ollama, Stub) must
 * follow. Queue jobs depend on this interface, never on a specific provider.
 * This means swapping the LLM backend is a one-line .env change, no job
 * code, controller code, or business logic needs to change.
 *
 * WHY an interface (Adapter Pattern):
 * The assignment requires supporting multiple LLM providers. Without an
 * interface, every queue job would need if/else branches.
 * This violates the Open/Closed Principle, adding a new provider means
 * modifying every job that uses the LLM. With an interface, adding a new
 * provider means creating one new class. Zero changes elsewhere.
 *
 * WHY these two methods:
 * The system uses the LLM for exactly two tasks:
 *   1. classifyEmail()  — Categorize an email (ClassifyEmailJob calls this)
 *   2. generateReply()  — Write a draft reply (GenerateDraftJob calls this)
 * Each method returns a typed DTO, so the caller always knows the exact
 * shape of the response regardless of which provider generated it.
 *
 * WHY DTOs as return types:
 * Every provider returns the same DTO types, which guarantees a consistent
 * response shape. If we returned arrays, each provider could structure its
 * response differently, and every caller would need provider specific parsing.
 */
interface LlmServiceInterface
{
    /**
     * Classify an email into one of four categories.
     *
     * Called by ClassifyEmailJob after a new email thread is fetched.
     * The result determines whether a draft reply is generated:
     *   - interested, meeting_request, unclear -> generate a draft
     *   - not_interested -> stop processing, no draft needed
     *
     * @param string $subject   The email's subject line
     * @param string $body      The email's plain text body
     * @param string $fromEmail The sender's email address (provides context
     *                          for the LLM to assess relevance)
     * @return ClassificationResult Validated, immutable classification
     */
    public function classifyEmail(string $subject, string $body, string $fromEmail): ClassificationResult;

    /**
     * Generate a professional reply to a classified email.
     *
     * Called by GenerateDraftJob for threads classified as interested,
     * meeting_request, or unclear. The reply is saved as a Gmail draft
     * for the user to review before sending.
     *
     * @param string $subject        The original email's subject line
     * @param string $body           The original email's plain text body
     * @param string $classification The classification result (e.g., "interested")
     *                               so the LLM can tailor its tone accordingly
     * @return DraftReplyResult Validated reply with subject, text body, and HTML body
     */
    public function generateReply(string $subject, string $body, string $classification): DraftReplyResult;
}
