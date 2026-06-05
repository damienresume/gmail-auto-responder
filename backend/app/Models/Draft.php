<?php

namespace App\Models;

/**
 * Draft Model
 *
 * PURPOSE: Represents an LLM-generated reply draft in its lifecycle from
 * creation through human review to sending. This is the human-in-the-loop
 * control layer — no email is ever sent without explicit user approval.
 *
 * WHY this model matters:
 *   - The entire auto-responder system exists to produce drafts, not sent emails.
 *     This is a deliberate architectural choice: AI-generated replies are too risky
 *     to auto-send. A bad reply can damage a business relationship permanently.
 *   - The draft lifecycle (generated → approved → sent) gives users full control.
 *     They can edit the LLM's output, discard it entirely, or regenerate it
 *     before anything leaves their inbox.
 *
 * WHY these design decisions:
 *   - Status as string constants: The lifecycle is simple (4 states) and unlikely
 *     to change. String constants keep it readable without enum complexity.
 *   - Scopes for dashboard views: The dashboard has three views (pending review,
 *     sent, all). Scopes encapsulate these queries so controllers stay thin.
 *   - markAsApproved/markAsSent methods: Business logic lives on the model
 *     (rich domain model pattern) rather than in controllers. This ensures the
 *     status transition rules are enforced consistently regardless of which
 *     controller or job triggers the transition.
 */

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Draft extends Model
{
    use HasFactory;

    // -------------------------------------------------------------------------
    // Status Constants
    // -------------------------------------------------------------------------
    // WHY these four states:
    //   - generated: LLM created the draft, waiting for human review
    //   - approved: User accepted the draft, ready to send via Gmail API
    //   - sent: Successfully sent via Gmail API (drafts.send)
    //   - discarded: User rejected the draft, will not be sent
    // The lifecycle is strictly one-directional: generated → approved → sent.
    // A draft can be discarded from any state except 'sent'.
    // -------------------------------------------------------------------------
    public const STATUS_GENERATED = 'generated';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_SENT = 'sent';
    public const STATUS_DISCARDED = 'discarded';

    public const VALID_STATUSES = [
        self::STATUS_GENERATED,
        self::STATUS_APPROVED,
        self::STATUS_SENT,
        self::STATUS_DISCARDED,
    ];

    protected $fillable = [
        'email_thread_id',
        'gmail_draft_id',
        'body_text',
        'body_html',
        'status',
        'revision',
        'sent_at',
    ];

    /**
     * WHY datetime cast on sent_at: Enables Carbon formatting in API responses
     * and consistent timezone handling. Null when the draft hasn't been sent.
     */
    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
            'revision' => 'integer',
        ];
    }

    // -------------------------------------------------------------------------
    // Relationships
    // -------------------------------------------------------------------------

    /**
     * The email thread this draft replies to.
     *
     * WHY belongsTo: Each draft is a reply to exactly one thread. This
     * relationship is used to: (1) display the original conversation alongside
     * the draft in the dashboard, (2) get the Gmail account's OAuth token
     * when sending the draft via the Gmail API.
     */
    public function emailThread(): BelongsTo
    {
        return $this->belongsTo(EmailThread::class);
    }

    // -------------------------------------------------------------------------
    // Scopes
    // -------------------------------------------------------------------------

    /**
     * Drafts awaiting human review (the dashboard's primary view).
     *
     * PURPOSE: This is the most frequently executed query in the system.
     * The partial index idx_drafts_pending_review on the database ensures
     * this query is fast regardless of total draft count — it only scans
     * the small subset of drafts with status = 'generated'.
     */
    public function scopePendingReview($query)
    {
        return $query->where('status', self::STATUS_GENERATED);
    }

    /**
     * Drafts that have been sent.
     *
     * PURPOSE: The dashboard's "Sent" tab shows the history of all
     * auto-responded emails for audit and review.
     */
    public function scopeSent($query)
    {
        return $query->where('status', self::STATUS_SENT);
    }

    // -------------------------------------------------------------------------
    // Business Logic
    // -------------------------------------------------------------------------

    /**
     * Transition the draft to "approved" status.
     *
     * PURPOSE: Called when the user clicks "Approve" in the dashboard.
     * This is a state guard — only generated drafts can be approved.
     * Prevents double-approving or approving an already-sent draft.
     *
     * WHY return bool: The controller checks the return value to decide
     * whether to return a 200 (success) or 409 (conflict) response.
     */
    public function markAsApproved(): bool
    {
        if ($this->status !== self::STATUS_GENERATED) {
            return false;
        }

        $this->update(['status' => self::STATUS_APPROVED]);
        return true;
    }

    /**
     * Transition the draft to "sent" status with the Gmail draft ID.
     *
     * PURPOSE: Called after the Gmail API successfully sends the draft.
     * Records both the send timestamp and the Gmail draft ID for audit
     * trail and potential undo functionality.
     *
     * WHY gmailDraftId parameter: The Gmail API returns a draft ID when
     * drafts.create is called. We store it so drafts.send can reference
     * the exact draft to send, and for post-send auditing.
     */
    public function markAsSent(?string $gmailDraftId = null): bool
    {
        if ($this->status !== self::STATUS_APPROVED) {
            return false;
        }

        $this->update([
            'status' => self::STATUS_SENT,
            'gmail_draft_id' => $gmailDraftId ?? $this->gmail_draft_id,
            'sent_at' => now(),
        ]);
        return true;
    }

    /**
     * Discard this draft (user decided not to send it).
     *
     * PURPOSE: Called when the user clicks "Discard" in the dashboard.
     * Can be called from any state except 'sent' — you can't unsend.
     */
    public function discard(): bool
    {
        if ($this->status === self::STATUS_SENT) {
            return false;
        }

        $this->update(['status' => self::STATUS_DISCARDED]);
        return true;
    }

    /**
     * Whether this draft can still be edited by the user.
     *
     * PURPOSE: The dashboard editor is read-only for sent/discarded drafts.
     * This method controls whether the "Edit" button is visible.
     */
    public function isEditable(): bool
    {
        return in_array($this->status, [
            self::STATUS_GENERATED,
            self::STATUS_APPROVED,
        ]);
    }
}
