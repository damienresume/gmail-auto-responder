<?php

/**
 * Gmail Accounts Migration
 *
 * PURPOSE: Stores OAuth credentials and sync state for each connected Gmail account.
 * This is the bridge between our system and Google's Gmail API without this table,
 * we cannot authenticate API calls to read emails or create drafts.
 *
 * WHY this design:
 *   - access_token and refresh_token are stored as TEXT and encrypted at the
 *     application layer via Laravel's `encrypted` cast on the Eloquent model.
 *     Database-level encryption (pgcrypto) was considered but rejected because
 *     it requires managing encryption keys in PostgreSQL config, adding a second
 *     secret management surface. Laravel's encrypter uses APP_KEY (already
 *     required) and AES-256-CBC, which is sufficient for OAuth tokens.
 *
 *   - token_expires_at enables proactive token refresh before expiry, avoiding
 *     failed Gmail API calls and the retry overhead they would cause.
 *
 *   - google_history_id tracks the last synced point per account. This enables
 *     incremental sync via Gmail's history.list API where we only fetch changes
 *     since the last known ID, not the entire inbox. This is O(new emails) per
 *     sync, not O(total emails). Critical for scaling to many accounts because:
 * 
 *       1. Gmail History Tracking: Every time an event occurs in an inbox (new mail,
 *          deletions, label changes), Google assigns it a sequential History ID. 
 *          We persist the latest ID here at the end of every sync cycle.
 * 
 *       2. The Next Sync Request: On the next execution, our worker pulls this string
 *          and passes it directly to Google via `history.list?startHistoryId={id}`.
 * 
 *       3. Streamlined Complexity: Instead of an O(total emails) brute-force operation
 *          forcing us to download thousands of IDs to find mismatches, Google filters 
 *          the ledger on their hardware. If 0 events happened, the response is O(1) 
 *          and empty. If 3 events happened, it returns O(3) data. Server workload scales 
 *          strictly with activity volume, completely decoupled from absolute mailbox size.
 *
 *   - Unique constraint on gmail_email prevents duplicate account connections,
 *     which would cause double-processing of every email.
 *
 *   - Composite index on (user_id, is_active) supports the dashboard query
 *     "show me my active Gmail accounts" without a full table scan.
 *
 *   - CASCADE delete on user_id removes the account record when the user is
 *     deleted — orphaned OAuth tokens are a security risk.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gmail_accounts', function (Blueprint $table) {
            $table->id();

            // WHY CASCADE: When a user deletes their account, their OAuth tokens
            // must be removed immediately. Orphaned tokens with no owning user
            // are a security liability — they grant Gmail access with no one to
            // revoke them.
            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete();

            // WHY UNIQUE: A single Gmail inbox should only be connected once.
            // Without this constraint, two rows for the same inbox would cause
            // every email to be fetched and classified twice, doubling LLM costs
            // and creating duplicate threads in the dashboard.
            $table->string('gmail_email')->unique();

            // WHY TEXT (not VARCHAR): OAuth tokens can vary in length depending
            // on Google's implementation. TEXT has no length limit in PostgreSQL
            // and avoids silent truncation. Encrypted at the application layer
            // by the Eloquent model — never stored in plaintext, never logged,
            // never exposed in API responses.
            $table->text('access_token');
            $table->text('refresh_token');

            // WHY TIMESTAMP: Gmail access tokens expire after 1 hour. Storing
            // expiry lets us proactively refresh 5 minutes early, avoiding the
            // scenario where a token expires mid-request and the Gmail API call
            // fails with a 401, triggering unnecessary retries.
            $table->timestamp('token_expires_at');

            // WHY NULLABLE: The first sync hasn't happened yet when the account
            // is initially created via OAuth. After the first successful sync,
            // this holds Gmail's history cursor. Each subsequent sync only
            // fetches changes after this point — O(new emails) not O(all emails).
            $table->string('google_history_id')->nullable();

            // WHY BOOLEAN: Soft toggle to pause syncing without deleting the
            // account or its data. Used when: (1) user temporarily disconnects,
            // (2) we detect repeated auth failures and want to stop retrying,
            // (3) user exceeds their plan limits.
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            // WHY COMPOSITE INDEX: The dashboard's "show active accounts for
            // this user" query filters on both columns. A single-column index
            // on user_id would still require scanning all rows for that user
            // to check is_active. The composite index covers both conditions.
            $table->index(['user_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gmail_accounts');
    }
};
