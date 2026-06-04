<?php

/**
 * Drafts Migration
 *
 * PURPOSE: Stores LLM-generated reply drafts and tracks their lifecycle from
 * generation through user review to sending. This table is the human-in-the-loop
 * control layer the user sees generated drafts in the dashboard and can approve,
 * edit, or discard them before anything is sent.
 *
 * WHY a separate table (not a column on email_threads):
 *   - A thread may have zero drafts (not_interested classification), one draft
 *     (first LLM generation), or multiple revisions (user edits and regenerates).
 *     A separate table with a revision counter handles all three cases cleanly.

 *   - Separating drafts from threads follows the Single Responsibility Principle:
 *     the threads table owns classification data, the drafts table owns reply data.
 *     Changes to draft logic (e.g., adding approval workflows) don't require
 *     altering the threads table.
 *
 * WHY these columns:
 *   - status tracks the draft lifecycle: generated -> approved -> sent (or discarded).
 *     The dashboard filters on this to show "needs review" vs "sent" views.
 *
 *   - revision is an integer counter incremented each time the LLM regenerates a
 *     reply for the same thread. The dashboard shows the latest revision.
 *
 *   - gmail_draft_id links to the Gmail API's draft object. When the user clicks
 *     "Send", we call drafts.send with this ID instead of creating a new message.
 *
 *   - sent_at is nullable and only populated when the draft is actually sent,
 *     distinct from updated_at which changes on every edit.
 *
 * WHY PARTIAL INDEX: The dashboard's primary view is "show drafts needing review"
 * which filters WHERE status = 'generated'. A partial index on this condition
 * keeps the index small (only pending drafts, not the entire history) and fast.
 * As drafts are approved and sent, they leave the partial index automatically.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('drafts', function (Blueprint $table) {
            $table->id();

            // WHY CASCADE: When a thread is deleted, its drafts become unsendable
            // (the thread context is gone). Cascade prevents orphaned drafts that
            // would appear in the dashboard with no associated conversation.
            $table->foreignId('email_thread_id')
                ->constrained('email_threads')
                ->cascadeOnDelete();

            // WHY NULLABLE: The Gmail draft ID is set after the Gmail API call
            // succeeds (drafts.create). If the API call fails, the draft row
            // still exists in our database for retry. Nullable handles both
            // the "not yet created in Gmail" and "API failed" states.
            $table->string('gmail_draft_id')->nullable();

            // WHY BOTH FORMATS: body_text is the LLM's raw output (plain text).
            // body_html is the formatted version rendered in the dashboard editor.
            // When the user edits a draft, both are updated to stay in sync.
            $table->text('body_text');
            $table->text('body_html')->nullable();

            // WHY STRING (not ENUM): Same reasoning as email_messages.direction —
            // Laravel doesn't handle PostgreSQL enum alterations well. A string
            // with application-level validation is safer for future changes.
            // Lifecycle: generated → approved → sent (or discarded at any point).
            $table->string('status')->default('generated')
                ->comment('generated|approved|sent|discarded');

            // WHY INTEGER: Simple counter incremented on each regeneration.
            // The dashboard always shows the highest revision. Keeping old
            // revisions would require a separate table — for now, each new
            // revision overwrites the body fields and bumps this counter.
            $table->unsignedInteger('revision')->default(1);

            // WHY NULLABLE: Only populated when the user clicks "Send" and the
            // Gmail API call succeeds. Null means the draft hasn't been sent yet.
            // Distinct from updated_at — a draft can be edited (updated_at changes)
            // without being sent (sent_at stays null).
            $table->timestamp('sent_at')->nullable();

            $table->timestamps();

            // WHY INDEX ON STATUS: The dashboard queries drafts by status
            // frequently (pending review, sent history). This index supports
            // both views.
            $table->index('status');
        });

        // WHY PARTIAL INDEX: PostgreSQL-specific feature not available in MySQL.
        // Only indexes rows where status = 'generated' (drafts needing review).
        // As the system processes thousands of emails, most drafts will be in
        // 'sent' or 'discarded' status. The partial index stays small because
        // it excludes completed drafts, making the dashboard's "needs review"
        // query consistently fast regardless of total draft count.
        // Raw SQL is required because Laravel's schema builder doesn't support
        // PostgreSQL partial indexes natively.
        DB::statement("
            CREATE INDEX idx_drafts_pending_review
            ON drafts (email_thread_id, created_at)
            WHERE status = 'generated'
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('drafts');
    }
};
