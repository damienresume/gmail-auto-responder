<?php

/**
 * Email Threads Migration
 *
 * PURPOSE: Stores Gmail thread metadata and LLM classification results. This is
 * the central table in the system the dashboard queries it to show threads with
 * their classifications, and queue jobs write classification results here after
 * the LLM processes each email.
 *
 * WHY this design:
 *   - One row per Gmail thread (not per message). Gmail groups related messages
 *     into threads automatically. Storing at the thread level means the dashboard
 *     shows "50 threads" instead of "200 messages" matching how users think
 *     about their inbox and reducing the number of LLM calls (classify once per
 *     thread, not once per message).
 *
 *   - Classification columns live directly on this table (not in a separate table)
 *     because every thread has exactly one classification. A join table would add
 *     query complexity with no normalization benefit it's a strict 1:1.
 *
 *   - gmail_thread_id is unique per gmail_account_id (composite unique key), not
 *     globally unique, because Gmail thread IDs are scoped to a single account.
 *     The composite unique key also enables idempotent upserts via PostgreSQL's
 *     ON CONFLICT DO NOTHING critical for handling Pub/Sub's at-least-once
 *     delivery guarantee without creating duplicate rows.
 *
 *   - metadata is JSONB (not JSON) because JSONB supports GIN indexes and native
 *     operators (->> , @>) for efficient querying. Regular JSON is stored as text
 *     and must be reparsed on every query.
 *
 *   - Classification columns are nullable because classification happens
 *     asynchronously the thread is created first by FetchNewEmailsJob, then
 *     classified later by ClassifyEmailJob.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_threads', function (Blueprint $table) {
            $table->id();

            // WHY CASCADE: When a Gmail account is disconnected, all its threads
            // become inaccessible (we lose the OAuth token to fetch them). Keeping
            // orphaned threads wastes storage and confuses the dashboard.
            $table->foreignId('gmail_account_id')
                ->constrained('gmail_accounts')
                ->cascadeOnDelete();

            // WHY STRING (not BIGINT): Gmail thread IDs are hexadecimal strings
            // like "18f3a2b4c5d6e7f8". Using string avoids conversion bugs and
            // matches the Gmail API response type exactly.
            $table->string('gmail_thread_id');

            // Thread metadata extracted from the first inbound message.
            // PURPOSE: Displayed in the dashboard thread list without needing
            // to join to email_messages or call the Gmail API again.
            $table->string('subject');
            $table->string('from_email');
            $table->string('from_name')->nullable();

            // WHY NULLABLE: Classification happens asynchronously after the
            // thread is stored. Between creation and classification, these are
            // null. The dashboard shows "Pending" for unclassified threads.
            $table->string('classification')->nullable()
                ->comment('interested|not_interested|meeting_request|unclear');

            // WHY DECIMAL(5,4): Stores confidence as 0.0000 to 1.0000. Four
            // decimal places give enough granularity to distinguish close calls
            // (e.g., 0.7823 vs 0.7891). DECIMAL avoids floating-point precision
            // issues that would make exact comparisons unreliable.
            $table->decimal('confidence_score', 5, 4)->nullable()
                ->comment('0.0000 to 1.0000 — LLM confidence in classification');

            // WHY TEXT: LLM reasoning can be several sentences. TEXT has no
            // length limit in PostgreSQL. Stored for auditability — if a user
            // questions why an email was classified a certain way, the reasoning
            // is available without re-running the LLM.
            $table->text('classification_reasoning')->nullable()
                ->comment('LLM explanation for the chosen classification');

            // WHY SEPARATE TIMESTAMP: classified_at is distinct from updated_at
            // because a thread can be updated for other reasons (e.g., new message
            // arrives). This field tells us exactly when the LLM ran.
            $table->timestamp('classified_at')->nullable();

            // WHY JSONB: PostgreSQL-specific column type that stores structured
            // data with native query operators. Stores Gmail labels, snippet,
            // internal date, and headers. Using JSONB instead of adding columns
            // for each field means we don't need a migration when Gmail adds new
            // metadata fields in future API versions.
            $table->jsonb('metadata')->nullable();

            $table->timestamps();

            // WHY COMPOSITE UNIQUE: Enables ON CONFLICT (gmail_account_id,
            // gmail_thread_id) DO NOTHING for idempotent upserts. Pub/Sub has
            // at-least-once delivery — the same notification can arrive twice.
            // Without this constraint, we'd create duplicate thread rows.
            $table->unique(['gmail_account_id', 'gmail_thread_id']);

            // WHY INDEX ON CLASSIFICATION: The dashboard's primary view filters
            // threads by classification status. Without this index, every
            // dashboard load would require a full table scan.
            $table->index('classification');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_threads');
    }
};
