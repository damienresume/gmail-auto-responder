<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\GmailAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Facades\Socialite;

/**
 * GoogleAuthController
 *
 * PURPOSE:
 * Handles the Google OAuth 2.0 flow that lets users connect their Gmail
 * account to our system. After connecting, we store the OAuth tokens in
 * the gmail_accounts table (encrypted) and can call the Gmail API on
 * their behalf.
 *
 * WHY two endpoints (redirect + callback):
 * OAuth 2.0 is a multi-step protocol:
 *   1. redirect() sends the user to Google's consent screen.
 *   2. Google redirects back to callback() with an authorization code.
 *   3. callback() exchanges the code for access + refresh tokens.
 * This is the standard OAuth 2.0 Authorization Code flow, which is the
 * most secure option for server-side applications (the tokens never pass
 * through the browser).
 *
 * SECURITY:
 *   - Tokens are encrypted at rest via the GmailAccount model's `encrypted` cast.
 *   - The authorization code is exchanged server-side (never exposed to the client).
 *   - Socialite handles CSRF protection via the `state` parameter automatically.
 *   - Gmail scopes are minimal: read-only access + draft creation. We do not
 *     request send permission — sending happens through drafts.send which
 *     requires the draft to already exist in the user's inbox.
 */
class GoogleAuthController extends Controller
{
    /**
     * Redirect the user to Google's OAuth consent screen.
     *
     * PURPOSE:
     * Initiates the OAuth flow. The user sees Google's permission dialog
     * asking them to grant our app access to their Gmail.
     *
     * WHY these scopes:
     *   - gmail.readonly: Read emails for classification. We never modify
     *     or delete the user's emails.
     *   - gmail.compose: Create drafts in the user's inbox. This is how
     *     LLM-generated replies become visible in Gmail.
     *   - userinfo.email: Get the user's email address to associate the
     *     Gmail account with their user record in our database.
     *
     * WHY 'access_type' => 'offline':
     * Requests a refresh token in addition to the access token. Without
     * this, we'd only get a short-lived access token (~1 hour) and would
     * need the user to re-authenticate every hour. The refresh token lets
     * us get new access tokens indefinitely without user interaction.
     *
     * WHY 'prompt' => 'consent':
     * Forces Google to show the consent screen every time, even if the
     * user previously authorized our app. This ensures we always get a
     * refresh token (Google only sends it on the first consent, unless
     * we force the prompt).
     */
    public function redirect(): RedirectResponse
    {
        return Socialite::driver('google')
            ->scopes([
                'https://www.googleapis.com/auth/gmail.readonly',
                'https://www.googleapis.com/auth/gmail.compose',
                'https://www.googleapis.com/auth/userinfo.email',
            ])
            ->with([
                'access_type' => 'offline',
                'prompt' => 'consent',
            ])
            ->redirect();
    }

    /**
     * Handle the callback from Google after the user grants permission.
     *
     * PURPOSE:
     * Exchanges the authorization code for tokens, creates or updates the
     * gmail_accounts record, and redirects the user to the dashboard.
     *
     * WHY updateOrCreate instead of just create:
     * A user might reconnect the same Gmail account (e.g., after revoking
     * access or if tokens expired). updateOrCreate handles both cases:
     *   - First connection: creates a new gmail_accounts row.
     *   - Reconnection: updates the existing row with fresh tokens.
     * This prevents duplicate rows for the same Gmail address.
     */
    public function callback(Request $request): JsonResponse|RedirectResponse
    {
        try {
            $googleUser = Socialite::driver('google')->user();

            // Store or refresh the Gmail account connection.
            // Tokens are automatically encrypted by the model's `encrypted` cast
            // before being written to the database.
            // Reset google_history_id on reconnect so the next sync does a
            // fresh fetch. Without this, reconnecting after clearing data
            // would skip re-fetching because the history ID thinks
            // everything was already synced.
            $account = GmailAccount::updateOrCreate(
                [
                    'user_id' => $request->user()->id,
                    'gmail_email' => $googleUser->getEmail(),
                ],
                [
                    'access_token' => $googleUser->token,
                    'refresh_token' => $googleUser->refreshToken ?? '',
                    'token_expires_at' => now()->addSeconds($googleUser->expiresIn ?? 3600),
                    'is_active' => true,
                    'google_history_id' => null,
                ],
            );

            Log::info('Gmail account connected', [
                'user_id' => $request->user()->id,
                'gmail_email' => $googleUser->getEmail(),
                'account_id' => $account->id,
            ]);

            // Dispatch fetch immediately so emails appear in the dashboard
            // right away instead of waiting for the 60-second scheduler.
            \App\Jobs\FetchNewEmailsJob::dispatch($account);

            return redirect(config('app.frontend_url', 'http://localhost:3000') . '/dashboard?gmail_connected=true');
        } catch (\Exception $e) {
            Log::error('Google OAuth callback failed', [
                'error' => $e->getMessage(),
            ]);

            return redirect(config('app.frontend_url', 'http://localhost:3000') . '/dashboard?gmail_error=true');
        }
    }
}
