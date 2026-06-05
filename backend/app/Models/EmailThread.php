<?php

namespace App\Models;

/**
 * EmailThread Model
 *
 * PURPOSE: Represents a Gmail thread with its LLM classification. This is the
 * primary entity in the dashboard — each row is one conversation the user can
 * view, classify, and respond to. The classification columns are written by
 * ClassifyEmailJob and read by the dashboard API.
 *
 * WHY these design decisions:
 *   - JSONB cast on metadata: Laravel's 'array' cast transparently converts
 *     between PHP arrays and PostgreSQL JSONB. This lets us store flexible
 *     Gmail metadata (labels, headers, snippet) without dedicated columns
 *     for each field.
 *   - Classification constants: Defined as class constants instead of a PHP
 *     enum because they're used in database queries (WHERE classification = ?)
 *     and enums add serialization overhead. Constants are simpler and the four
 *     values are unlikely to change.
 *   - Scopes for dashboard queries: scopeUnclassified, scopeByClassification,
 *     and scopeForAccount encapsulate the WHERE clauses the dashboard uses
 *     most frequently. This keeps controllers thin and query logic reusable.
 */

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class EmailThread extends Model
{
    use HasFactory;

    // -------------------------------------------------------------------------
    // Classification Constants
    // -------------------------------------------------------------------------
    // WHY constants: Used by ClassifyEmailJob to validate LLM output and by
    // the dashboard API to filter threads. A PHP enum was considered but adds
    // serialization complexity when storing in the database. String constants
    // match the database column type directly.
    // -------------------------------------------------------------------------
    public const CLASSIFICATION_INTERESTED = 'interested';
    public const CLASSIFICATION_NOT_INTERESTED = 'not_interested';
    public const CLASSIFICATION_MEETING_REQUEST = 'meeting_request';
    public const CLASSIFICATION_UNCLEAR = 'unclear';

    /**
     * All valid classification values.
     *
     * PURPOSE: Used by ClassifyEmailJob to validate the LLM's response.
     * If the LLM returns an unexpected value, we reject it and mark the
     * thread as 'unclear' rather than storing garbage data.
     */
    public const VALID_CLASSIFICATIONS = [
        self::CLASSIFICATION_INTERESTED,
        self::CLASSIFICATION_NOT_INTERESTED,
        self::CLASSIFICATION_MEETING_REQUEST,
        self::CLASSIFICATION_UNCLEAR,
    ];

    protected $fillable = [
        'gmail_account_id',
        'gmail_thread_id',
        'subject',
        'from_email',
        'from_name',
        'classification',
        'confidence_score',
        'classification_reasoning',
        'classified_at',
        'metadata',
    ];

    /**
     * WHY these casts:
     *   - 'array' on metadata: Transparently converts JSONB ↔ PHP array.
     *     We can do $thread->metadata['labels'] in PHP without json_decode().
     *   - 'decimal:4' on confidence_score: Preserves 4 decimal places and
     *     returns a string to avoid floating-point precision loss.
     *   - 'datetime' on classified_at: Enables Carbon methods like
     *     $thread->classified_at->diffForHumans() in the dashboard API.
     */
    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'confidence_score' => 'decimal:4',
            'classified_at' => 'datetime',
        ];
    }

    // -------------------------------------------------------------------------
    // Relationships
    // -------------------------------------------------------------------------

    /**
     * The Gmail account this thread was fetched from.
     *
     * WHY belongsTo: Each thread belongs to exactly one Gmail account.
     * Used to get the OAuth token when we need to fetch thread details
     * or create drafts via the Gmail API.
     */
    public function gmailAccount(): BelongsTo
    {
        return $this->belongsTo(GmailAccount::class);
    }

    /**
     * Individual messages within this thread.
     *
     * WHY hasMany ordered by received_at: Messages are displayed
     * chronologically in the dashboard's conversation view. Defining
     * the default order here means every caller gets consistent ordering
     * without repeating the ORDER BY clause.
     */
    public function messages(): HasMany
    {
        return $this->hasMany(EmailMessage::class)->orderBy('received_at');
    }

    /**
     * The latest active draft reply for this thread.
     *
     * WHY hasOne with latestOfMany: A thread can have multiple draft
     * revisions, but the dashboard always shows the most recent one.
     * hasOne()->latestOfMany() returns only the newest draft without
     * loading all revisions into memory.
     *
     * WHY exclude discarded: Discarded drafts are rejected by the user
     * and should not appear in the thread detail view. Without this
     * filter, discarding a draft would still show it in the UI because
     * latestOfMany would pick it up as the most recent record.
     */
    public function latestDraft(): HasOne
    {
        return $this->hasOne(Draft::class)
            ->whereNotIn('status', [Draft::STATUS_DISCARDED])
            ->latestOfMany();
    }

    /**
     * All draft revisions for this thread.
     *
     * WHY hasMany: Provides access to the full revision history when
     * needed (e.g., audit log, undo functionality).
     */
    public function drafts(): HasMany
    {
        return $this->hasMany(Draft::class, 'email_thread_id');
    }

    // -------------------------------------------------------------------------
    // Scopes
    // -------------------------------------------------------------------------

    /**
     * Threads that haven't been classified yet.
     *
     * PURPOSE: Used by the dashboard to show a "Pending" tab and by the
     * classification retry job to find threads that failed classification.
     */
    public function scopeUnclassified($query)
    {
        return $query->whereNull('classification');
    }

    /**
     * Threads filtered by a specific classification.
     *
     * PURPOSE: The dashboard has tabs for each classification type.
     * This scope is called with the tab name as the parameter.
     */
    public function scopeByClassification($query, string $classification)
    {
        return $query->where('classification', $classification);
    }

    /**
     * Threads belonging to a specific Gmail account.
     *
     * PURPOSE: A user with multiple Gmail accounts can filter the dashboard
     * to show threads from one account at a time.
     */
    public function scopeForAccount($query, int $gmailAccountId)
    {
        return $query->where('gmail_account_id', $gmailAccountId);
    }

    // -------------------------------------------------------------------------
    // Business Logic
    // -------------------------------------------------------------------------

    /**
     * Whether this thread has been classified by the LLM.
     *
     * PURPOSE: Guards against accessing classification data before it exists.
     * Controllers check this before rendering classification details to avoid
     * null reference errors in the API response.
     */
    public function isClassified(): bool
    {
        return $this->classification !== null;
    }

    /**
     * Whether this classification warrants generating a draft reply.
     *
     * PURPOSE: Called by ClassifyEmailJob after classification to decide
     * whether to dispatch GenerateDraftJob. Only "interested", "meeting_request",
     * and "unclear" threads get drafts. "not_interested" threads stop here.
     *
     * WHY "unclear" gets a draft: It's better to generate a cautious reply
     * the user can discard than to miss a potentially important email. The
     * user is the final decision-maker.
     */
    public function shouldGenerateDraft(): bool
    {
        return in_array($this->classification, [
            self::CLASSIFICATION_INTERESTED,
            self::CLASSIFICATION_MEETING_REQUEST,
            self::CLASSIFICATION_UNCLEAR,
        ]);
    }
}
