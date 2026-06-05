<?php

/**
 * Email Messages Migration
 *
 * PURPOSE: Stores individual messages within a Gmail thread. While threads are
 * classified as a unit, the LLM needs the actual message bodies to generate
 * context-aware draft replies. The dashboard also displays individual messages
 * in a conversation view when the user expands a thread.
 *
 * WHY a separate table (not merged into email_threads):
 *   - A Gmail thread can contain 1 to N messages (replies, forwards). Storing
 *     messages as rows enables: (1) showing a conversation history view in the
 *     dashboard, (2) feeding the full conversation context to the LLM for better
 *     reply generation, (3) tracking which messages are inbound vs outbound.
 *
 *   - If we stored only the latest message on the thread row, we'd lose
 *     conversation context and the LLM would generate worse replies.
 *
 * WHY these columns:
 *   - direction distinguishes inbound (received) from outbound (sent/drafted).
 *     The dashboard uses this to render messages differently (left vs right
 *     alignment), and the LLM uses it to understand who said what.
 *
 *   - body_text and body_html store both formats because: (1) the LLM processes
 *     plain text (cheaper tokens, no HTML parsing), (2) the dashboard renders
 *     HTML for visual fidelity. Storing both avoids on-the-fly conversion.
 *
 *   - gmail_message_id is unique per thread for idempotent upserts the same
 *     Pub/Sub notification can trigger fetching the same message twice.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_messages', function (Blueprint $table) {
            $table->id();

            // WHY CASCADE: When a thread is deleted (e.g., account disconnected),
            // its messages are meaningless without the thread context. Cascade
            // prevents orphaned message rows accumulating in the database.
            $table->foreignId('email_thread_id')
                ->constrained('email_threads')
                ->cascadeOnDelete();

            // WHY STRING: Gmail message IDs are hexadecimal strings scoped to an
            // account. Unique within the thread to prevent duplicate message rows
            // when Pub/Sub delivers the same notification multiple times.
            $table->string('gmail_message_id')->unique();

            // WHY STRING ENUM (not PostgreSQL ENUM type): Laravel's migration
            // builder doesn't natively support ALTER TYPE for PostgreSQL enums,
            // making it painful to add new values (e.g., "forwarded") later.
            // A string column with application-level validation is more flexible
            // and avoids migration headaches when requirements change.
            $table->string('direction')
                ->comment('inbound|outbound — who sent this message');

            // WHY BOTH TEXT FORMATS: body_text is fed to the LLM (plain text =
            // fewer tokens = lower cost and better classification accuracy).
            // body_html is rendered in the dashboard for visual fidelity (bold,
            // links, formatting). Storing both avoids runtime HTML-to-text
            // conversion, which is error-prone and adds latency.
            $table->text('body_text')->nullable();
            $table->text('body_html')->nullable();

            // WHY SEPARATE FROM created_at: received_at is when Gmail received
            // the message (from Gmail's internalDate). created_at is when our
            // system stored it. The difference is the processing lag. The
            // dashboard sorts by received_at to match the user's inbox order.
            $table->timestamp('received_at')->nullable();

            $table->timestamps();

            // WHY INDEX: The dashboard's thread detail view queries messages
            // by thread, ordered by received_at. This index covers both the
            // WHERE clause and the ORDER BY, avoiding a filesort.
            $table->index(['email_thread_id', 'received_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_messages');
    }
};
