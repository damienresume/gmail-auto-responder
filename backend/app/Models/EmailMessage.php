<?php

namespace App\Models;

/**
 * EmailMessage Model
 *
 * PURPOSE: Represents a single message within a Gmail thread. Messages are the
 * raw content that the LLM reads for classification and reply generation. The
 * dashboard displays them in a conversation view when a user expands a thread.
 *
 * WHY this model exists:
 *   - Gmail threads contain multiple messages (original email + replies).
 *   - The LLM needs the full conversation context to generate relevant replies.
 *     Feeding only the latest message would miss important context like
 *     "as I mentioned in my previous email..." references.
 *   - The dashboard shows a conversation timeline with inbound messages on one
 *     side and outbound (sent/drafted) on the other, which requires knowing
 *     the direction of each message.
 *
 * WHY these design decisions:
 *   - Direction constants instead of enum: 'inbound' and 'outbound' are stored
 *     as strings to avoid PostgreSQL enum migration issues (see migration comments).
 *   - body_text preferred for LLM: Plain text is cheaper (fewer tokens) and
 *     produces more reliable classification than HTML with its tags and styling.
 *   - received_at from Gmail's internalDate: This is when Gmail received the
 *     message, not when our system processed it. The dashboard sorts by this
 *     to match the user's Gmail inbox ordering.
 */

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmailMessage extends Model
{
    use HasFactory;

    // WHY constants: Used by FetchNewEmailsJob to set direction when storing
    // messages. Constants prevent typos like 'Inbound' vs 'inbound' that would
    // break dashboard filtering.
    public const DIRECTION_INBOUND = 'inbound';
    public const DIRECTION_OUTBOUND = 'outbound';

    protected $fillable = [
        'email_thread_id',
        'gmail_message_id',
        'direction',
        'body_text',
        'body_html',
        'received_at',
    ];

    /**
     * WHY datetime cast: Converts the PostgreSQL timestamp to a Carbon instance,
     * enabling readable formatting in API responses like "2 hours ago" and
     * consistent timezone handling across the application.
     */
    protected function casts(): array
    {
        return [
            'received_at' => 'datetime',
        ];
    }

    // -------------------------------------------------------------------------
    // Relationships
    // -------------------------------------------------------------------------

    /**
     * The thread this message belongs to.
     *
     * WHY belongsTo: Every message is part of exactly one thread. This
     * relationship is used to navigate from a message back to its thread
     * and from there to the Gmail account (for API calls).
     */
    public function emailThread(): BelongsTo
    {
        return $this->belongsTo(EmailThread::class);
    }

    // -------------------------------------------------------------------------
    // Business Logic
    // -------------------------------------------------------------------------

    /**
     * Whether this message was received from an external sender.
     *
     * PURPOSE: The LLM only classifies and replies to inbound messages.
     * Outbound messages (our sent replies) are stored for conversation
     * context but don't trigger new classifications.
     */
    public function isInbound(): bool
    {
        return $this->direction === self::DIRECTION_INBOUND;
    }
}
