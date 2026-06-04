<?php

namespace App\DTOs;

/**
 * ClassificationResult
 *
 * PURPOSE:
 * When the LLM classifies an email, it returns raw JSON with a category,
 * confidence score, and reasoning. This class wraps that response into a
 * validated, typed object so the rest of the application never deals with
 * raw JSON or guesses what fields exist.
 *
 * WHY a DTO:
 *   A readonly DTO gives us typed properties, constructor
 *   validation, and immutability, all with zero
 *   framework dependencies.
 *
 * WHY readonly:
 * The `readonly` keyword means every property can only be set once, inside
 * the constructor. After that, the object is frozen. This prevents bugs
 * where downstream code accidentally changes a classification result,
 * for example, a retry loop overwriting the confidence score.
 */
readonly class ClassificationResult
{
    /**
     * @param string $classification One of: interested, not_interested, meeting_request, unclear
     * @param float  $confidence     0.0 to 1.0 — how confident the LLM is
     * @param string $reasoning      The LLM's explanation for its choice
     */
    public function __construct(
        public string $classification,
        public float $confidence,
        public string $reasoning,
    ) {}

    /**
     * Create a ClassificationResult from raw LLM JSON.
     *
     * PURPOSE:
     * LLM responses are unpredictable, they can have missing fields,
     * unexpected casing ("INTERESTED" vs "interested"), or out-of-range
     * values. This factory method validates and normalizes the response
     * once, at the point of entry. Everything downstream gets clean data.
     *
     * WHY a static factory:
     * The constructor accepts pre-validated values, which makes it easy to
     * create instances in tests without building fake JSON. The factory
     * handles the messy parsing; the constructor handles the clean path.
     */
    public static function fromLlmResponse(array $response): self
    {
        // Normalize to lowercase because LLMs return inconsistent casing.
        // "Interested", "MEETING_REQUEST", and "interested" all mean the same thing.
        $classification = strtolower(trim($response['classification'] ?? 'unclear'));

        // If the LLM returns something unexpected (e.g., "maybe" or "spam"),
        // default to "unclear". This triggers draft generation, which is safer
        // than ignoring a potentially important email. The user can always
        // discard the draft if it was unnecessary.
        $validClassifications = ['interested', 'not_interested', 'meeting_request', 'unclear'];
        if (!in_array($classification, $validClassifications, true)) {
            $classification = 'unclear';
        }

        // Clamp confidence to 0.0–1.0. LLMs occasionally return values like
        // 1.2 or -0.5. Without clamping, these would violate the database
        // column constraint (DECIMAL 5,4 maxes at 1.0000) and throw an error.
        // Clamping confience allows you to restrict or fix the model's reported
        // certainty scores within a mathematical boundary.
        $confidence = max(0.0, min(1.0, (float) ($response['confidence'] ?? 0.5)));

        $reasoning = trim($response['reasoning'] ?? 'No reasoning provided by LLM.');

        return new self(
            classification: $classification,
            confidence: $confidence,
            reasoning: $reasoning,
        );
    }

    /**
     * Convert to an array that matches the email_threads table columns.
     *
     * Used by ClassifyEmailJob to update a thread in one call:
     *   $thread->update($result->toArray());
     */
    public function toArray(): array
    {
        return [
            'classification' => $this->classification,
            'confidence_score' => $this->confidence,
            'classification_reasoning' => $this->reasoning,
            'classified_at' => now(),
        ];
    }
}
