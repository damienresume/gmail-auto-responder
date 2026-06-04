<?php

namespace App\Models;

/**
 * GmailAccount Model
 *
 * PURPOSE: Represents a connected Gmail account with OAuth credentials.
 * This model is the gateway to all Gmail API operations — every API call
 * uses the access_token stored here, and every sync operation reads/writes
 * the google_history_id to track incremental progress.
 *
 * WHY these design decisions:
 *   - Encrypted casts on tokens: access_token and refresh_token are automatically
 *     encrypted when written to the database and decrypted when read. This uses
 *     Laravel's APP_KEY with AES-256-CBC. If the database is compromised, tokens
 *     are unreadable without the application key.
 *   - Hidden attributes: Tokens and sensitive fields are excluded from JSON
 *     serialization by default. Even if a developer accidentally returns a
 *     GmailAccount in an API response, tokens won't leak.
 *   - isTokenExpired() method centralizes the 5-minute buffer logic. Every
 *     place that uses a token calls this method instead of duplicating the
 *     "is it about to expire?" check. DRY principle.
 *   - Relationship to User is belongsTo (many accounts can belong to one user).
 *     Relationship to EmailThread is hasMany (one account has many threads).
 */

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GmailAccount extends Model
{
    use HasFactory;

    /**
     * Mass-assignable attributes.
     *
     * WHY these fields: These are the fields set during OAuth callback
     * (account creation) and token refresh (credential update). Other
     * fields like id and timestamps are managed by Eloquent automatically.
     */
    protected $fillable = [
        'user_id',
        'gmail_email',
        'access_token',
        'refresh_token',
        'token_expires_at',
        'google_history_id',
        'is_active',
    ];

    /**
     * Attributes hidden from JSON serialization.
     *
     * WHY: OAuth tokens are the keys to a user's Gmail inbox. If these
     * leak in an API response, an attacker can read all their email.
     * Hiding them by default is defense-in-depth — even if a controller
     * forgets to select specific columns, tokens won't appear in output.
     */
    protected $hidden = [
        'access_token',
        'refresh_token',
    ];

    /**
     * Attribute casts.
     *
     * WHY encrypted: Stores tokens as AES-256-CBC ciphertext in the database.
     * If an attacker gains read access to PostgreSQL (SQL injection, backup
     * theft, compromised replica), they get ciphertext, not usable tokens.
     * Decryption requires APP_KEY which lives in the application environment,
     * not in the database.
     *
     * WHY datetime for token_expires_at: Enables Carbon date comparisons
     * like $account->token_expires_at->isPast() without manual parsing.
     *
     * WHY boolean for is_active: PostgreSQL stores booleans natively, but
     * the cast ensures PHP always gets true/false (not 0/1 or 't'/'f').
     */
    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'token_expires_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    // -------------------------------------------------------------------------
    // Relationships
    // -------------------------------------------------------------------------

    /**
     * The user who owns this Gmail account connection.
     *
     * WHY belongsTo: A Gmail account is always owned by exactly one user.
     * The foreign key user_id on this table points to users.id.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * All email threads fetched from this Gmail account.
     *
     * WHY hasMany: One Gmail account can have thousands of threads.
     * Each thread row references this account via gmail_account_id.
     */
    public function emailThreads(): HasMany
    {
        return $this->hasMany(EmailThread::class);
    }

    // -------------------------------------------------------------------------
    // Business Logic
    // -------------------------------------------------------------------------

    /**
     * Check if the OAuth access token has expired or is about to expire.
     *
     * PURPOSE: Called before every Gmail API request to determine if we need
     * to refresh the token first. The 5-minute buffer prevents the edge case
     * where a token expires between the check and the actual API call.
     *
     * WHY 5 minutes: Gmail API calls can take 1-3 seconds. Network retries
     * add more time. A 5-minute buffer provides a comfortable margin without
     * triggering unnecessary refreshes (tokens last 1 hour, so refreshing
     * 5 minutes early means we use 91.7% of each token's lifetime).
     *
     * @param int $bufferSeconds Seconds before actual expiry to consider "expired"
     */
    public function isTokenExpired(int $bufferSeconds = 300): bool
    {
        return $this->token_expires_at->subSeconds($bufferSeconds)->isPast();
    }

    // -------------------------------------------------------------------------
    // Scopes
    // -------------------------------------------------------------------------

    /**
     * Scope to only active accounts that are eligible for syncing.
     *
     * PURPOSE: Used by the sync scheduler to find accounts that should be
     * checked for new emails. Inactive accounts are skipped entirely,
     * avoiding wasted API calls and token refresh attempts.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope to accounts with expired tokens that need refreshing.
     *
     * PURPOSE: Used by a scheduled task to proactively refresh tokens
     * before they expire, rather than waiting for a failed API call.
     * Batch-refreshing is more efficient than refreshing on-demand
     * because it avoids blocking individual API requests.
     */
    public function scopeTokenExpiringSoon($query, int $bufferSeconds = 300)
    {
        return $query->where('is_active', true)
            ->where('token_expires_at', '<=', now()->addSeconds($bufferSeconds));
    }
}
