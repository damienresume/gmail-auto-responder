<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\FetchNewEmailsJob;
use App\Models\GmailAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * GmailWebhookController
 *
 * PURPOSE:
 * Receives push notifications from Google Cloud Pub/Sub when a new email
 * arrives in a connected Gmail inbox. Decodes the notification, identifies
 * the Gmail account, and dispatches FetchNewEmailsJob to process the email.
 *
 * WHY a webhook instead of polling:
 * Polling 1,000 accounts every 30 seconds = 2.88 million API calls/day,
 * mostly returning "nothing new." Pub/Sub push sends a notification only
 * when an email arrives, costing exactly 1 notification per email. Idle
 * inboxes cost zero.
 *
 * HOW Pub/Sub push works:
 *   1. Google watches the user's Gmail inbox (set up via Gmail API watch).
 *   2. When a new email arrives, Google publishes a message to a Pub/Sub topic.
 *   3. Pub/Sub pushes an HTTP POST to this endpoint with the notification.
 *   4. We decode it, find the matching Gmail account, and dispatch a job.
 *   5. We respond 200 immediately so Pub/Sub doesn't retry the notification.
 *
 * SECURITY:
 *   - This endpoint is intentionally public (no auth middleware). Pub/Sub
 *     cannot send authentication headers. Instead, we validate the request
 *     by checking that the email address in the notification matches an
 *     active Gmail account in our database. If it doesn't match, we return
 *     200 (to stop retries) but do nothing.
 *   - The notification payload only contains the email address and history ID,
 *     not email content. Actual email content is fetched server-side via the
 *     Gmail API with the stored OAuth token.
 */
class GmailWebhookController extends Controller
{
    /**
     * Handle an incoming Pub/Sub push notification.
     *
     * WHY always return 200:
     * Pub/Sub retries on non-2xx responses with exponential backoff.
     * If we return 400 or 500 for an invalid notification, Pub/Sub will
     * keep retrying it forever (up to 7 days). Returning 200 acknowledges
     * the notification and stops retries, even for notifications we choose
     * to ignore (unknown email, duplicate, etc.).
     */
    public function handle(Request $request): JsonResponse
    {
        $data = $request->input('message.data');

        if (empty($data)) {
            Log::warning('Gmail webhook received empty notification');
            return response()->json(['status' => 'ignored']);
        }

        // Pub/Sub encodes the message payload as base64.
        // The decoded payload is JSON containing the Gmail address and history ID.
        $decoded = json_decode(base64_decode($data), true);

        if (!$decoded || empty($decoded['emailAddress'])) {
            Log::warning('Gmail webhook received malformed notification', [
                'raw' => $data,
            ]);
            return response()->json(['status' => 'ignored']);
        }

        $emailAddress = $decoded['emailAddress'];

        // Find the matching Gmail account. If the email doesn't match any
        // active account, it could be a stale notification for a disconnected
        // account or a spoofed request. Either way, we ignore it.
        $account = GmailAccount::where('gmail_email', $emailAddress)
            ->active()
            ->first();

        if (!$account) {
            Log::info('Gmail webhook: no active account for email', [
                'email' => $emailAddress,
            ]);
            return response()->json(['status' => 'ignored']);
        }

        // Dispatch the fetch job to process new emails asynchronously.
        // The webhook responds immediately (under 1 second) while the actual
        // Gmail API calls happen in the background via Horizon.
        FetchNewEmailsJob::dispatch($account);

        Log::info('Gmail webhook dispatched fetch job', [
            'account_id' => $account->id,
            'email' => $emailAddress,
        ]);

        return response()->json(['status' => 'ok']);
    }
}
