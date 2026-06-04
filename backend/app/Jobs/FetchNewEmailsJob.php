<?php

namespace App\Jobs;

use App\Models\EmailMessage;
use App\Models\EmailThread;
use App\Models\GmailAccount;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * FetchNewEmailsJob
 *
 * PURPOSE:
 * Fetches new emails from a Gmail account using Google's incremental
 * history API. This is the first step in the pipeline:
 *   Pub/Sub webhook -> FetchNewEmailsJob -> ClassifyEmailJob -> GenerateDraftJob
 *
 * WHY a queue job instead of fetching inline in the webhook:
 *   - Speed: The webhook must respond to Google within 10 seconds or
 *     Pub/Sub retries the notification. Fetching emails can take several
 *     seconds (multiple API calls), so we acknowledge the webhook immediately
 *     and do the work asynchronously.
 *
 *   - Reliability: If the Gmail API is temporarily down, the job retries
 *     automatically with exponential backoff. An inline fetch would lose
 *     the notification entirely.
 *
 *   - Isolation: A slow or failing Gmail API call doesn't block the webhook
 *     endpoint from processing other notifications.
 *
 * HOW it works:
 *   1. Receives a GmailAccount (with encrypted OAuth token).
 *   2. Calls Gmail's history.list API using the account's stored history ID
 *      to get only new message IDs since the last sync.
 *   3. For each new message, calls messages.get to fetch the full content.
 *   4. Stores the thread and message in our database (idempotent upserts).
 *   5. Updates the account's history ID for the next sync.
 *   6. Dispatches ClassifyEmailJob for each new thread.
 *
 * QUEUE: 'gmail-ingest', highest priority, 3 workers in Horizon config.
 * This ensures new emails are picked up quickly even when the classification
 * and draft queues are busy.
 */
class FetchNewEmailsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * WHY 3 tries: Gmail API can have transient failures (rate limits,
     * 500 errors). Three attempts with backoff covers most temporary issues.
     * If it still fails after 3 tries, it goes to the failed_jobs table
     * for manual investigation.
     */
    public int $tries = 3;

    /**
     * WHY exponential backoff [30, 120, 300]:
     * First retry after 30 seconds (covers brief rate limits).
     * Second retry after 2 minutes (covers short outages).
     * Third retry after 5 minutes (covers longer API issues).
     * This avoids hammering a struggling API with immediate retries.
     */
    public array $backoff = [30, 120, 300];

    public function __construct(
        private readonly GmailAccount $account,
    ) {
        // WHY specifying the queue in the constructor:
        // Horizon routes jobs to workers based on the queue name.
        // 'gmail-ingest' has 3 dedicated workers (highest allocation)
        // because fetching emails is the entry point, if this is slow,
        // the entire pipeline stalls.
        $this->onQueue('gmail-ingest');
    }

    public function handle(): void
    {
        // Skip inactive accounts. The account could have been deactivated
        // between when the job was dispatched and when it runs.
        if (!$this->account->is_active) {
            Log::info('Skipping inactive Gmail account', [
                'account_id' => $this->account->id,
            ]);
            return;
        }

        $historyId = $this->account->google_history_id;

        // First sync: no history ID means we haven't synced this account yet.
        // Use messages.list to get recent messages instead of history.list.
        if (empty($historyId)) {
            $this->performInitialSync();
            return;
        }

        $this->performIncrementalSync($historyId);
    }

    /**
     * First-time sync for a newly connected account.
     *
     * PURPOSE:
     * When a user first connects their Gmail, we have no history ID yet.
     * We fetch the 10 most recent messages to seed the dashboard with data,
     * then store the latest history ID for future incremental syncs.
     *
     * WHY only 10 messages:
     * Fetching the entire inbox on first connect would take minutes and
     * consume a large chunk of the Gmail API quota. 10 messages gives the
     * user immediate value (they see recent emails in the dashboard) while
     * keeping the first sync fast. Older emails can be loaded on demand.
     */
    private function performInitialSync(): void
    {
        try {
            $response = Http::withToken($this->account->access_token)
                ->timeout(15)
                ->get('https://gmail.googleapis.com/gmail/v1/users/me/messages', [
                    'maxResults' => 10,
                ]);

            if (!$response->successful()) {
                Log::error('Gmail initial sync failed', [
                    'account_id' => $this->account->id,
                    'status' => $response->status(),
                ]);
                $this->handleApiError($response->status());
                return;
            }

            $messages = $response->json('messages', []);

            foreach ($messages as $messageRef) {
                $this->fetchAndStoreMessage($messageRef['id']);
            }

            // Store the profile's current history ID for future incremental syncs.
            $this->updateHistoryId();
        } catch (\Exception $e) {
            Log::error('Gmail initial sync exception', [
                'account_id' => $this->account->id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Incremental sync using Gmail's history API.
     *
     * PURPOSE:
     * Fetches only new events since the last stored history ID. This is
     * O(new emails) not O(total emails), the core scaling mechanism.
     *
     * WHY history.list instead of messages.list with a date filter:
     * history.list is Google's recommended approach for detecting changes.
     * It catches not just new messages but also label changes, deletions,
     * and other events. Date filtering with messages.list would miss
     * messages that arrive out of order (delayed delivery, imports).
     */
    private function performIncrementalSync(string $historyId): void
    {
        try {
            $response = Http::withToken($this->account->access_token)
                ->timeout(15)
                ->get('https://gmail.googleapis.com/gmail/v1/users/me/history', [
                    'startHistoryId' => $historyId,
                    'historyTypes' => 'messageAdded',
                    // WHY only 'messageAdded': We only care about new emails
                    // for classification. Other history types (labelAdded,
                    // messageDeleted) are irrelevant to our pipeline and
                    // would create unnecessary processing.
                ]);

            if (!$response->successful()) {
                // WHY special handling for 404: Gmail returns 404 when the
                // history ID is too old (expired). The fix is to reset and
                // do a fresh initial sync. This is expected behavior, not
                // an error — history IDs expire after ~30 days of inactivity.
                if ($response->status() === 404) {
                    Log::warning('History ID expired, resetting to initial sync', [
                        'account_id' => $this->account->id,
                    ]);
                    $this->account->update(['google_history_id' => null]);
                    $this->performInitialSync();
                    return;
                }

                Log::error('Gmail incremental sync failed', [
                    'account_id' => $this->account->id,
                    'status' => $response->status(),
                ]);
                $this->handleApiError($response->status());
                return;
            }

            $historyRecords = $response->json('history', []);

            foreach ($historyRecords as $record) {
                foreach ($record['messagesAdded'] ?? [] as $added) {
                    $this->fetchAndStoreMessage($added['message']['id']);
                }
            }

            $this->updateHistoryId();
        } catch (\Exception $e) {
            Log::error('Gmail incremental sync exception', [
                'account_id' => $this->account->id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Fetch a single message from Gmail and store it in our database.
     *
     * PURPOSE:
     * Translates a Gmail message into our data model: creates or finds the
     * thread, stores the message content, and dispatches classification.
     *
     * WHY upsert pattern (updateOrCreate / firstOrCreate):
     * Pub/Sub has at-least-once delivery. The same notification can arrive
     * twice, causing this method to be called for the same message twice.
     * updateOrCreate on the thread and firstOrCreate on the message ensure
     * duplicates are silently ignored rather than causing unique constraint
     * violations.
     */
    private function fetchAndStoreMessage(string $messageId): void
    {
        try {
            $response = Http::withToken($this->account->access_token)
                ->timeout(15)
                ->get("https://gmail.googleapis.com/gmail/v1/users/me/messages/{$messageId}", [
                    'format' => 'full',
                ]);

            if (!$response->successful()) {
                Log::warning('Failed to fetch message', [
                    'message_id' => $messageId,
                    'status' => $response->status(),
                ]);
                return;
            }

            $data = $response->json();
            $headers = collect($data['payload']['headers'] ?? []);

            $subject = $headers->firstWhere('name', 'Subject')['value'] ?? '(No Subject)';
            $from = $headers->firstWhere('name', 'From')['value'] ?? '';
            $threadId = $data['threadId'] ?? '';

            // Parse the "From" header into email and name components.
            // Format is either "name <email>" or just "email".
            $fromEmail = $from;
            $fromName = null;
            if (preg_match('/^(.+?)\s*<(.+?)>$/', $from, $matches)) {
                $fromName = trim($matches[1], ' "\'');
                $fromEmail = $matches[2];
            }

            // Determine direction early so we can decide what to store on the thread.
            $direction = (strtolower($fromEmail) === strtolower($this->account->gmail_email))
                ? EmailMessage::DIRECTION_OUTBOUND
                : EmailMessage::DIRECTION_INBOUND;

            // Upsert the thread. Only set from_name/from_email from INBOUND
            // messages so the thread always shows the external sender's name,
            // not the user's own name. If the first message is outbound, we
            // still create the thread but leave from_name/email to be updated
            // when the first inbound message arrives.
            $threadData = [
                'subject' => $subject,
                'metadata' => [
                    'labels' => $data['labelIds'] ?? [],
                    'snippet' => $data['snippet'] ?? '',
                    'internal_date' => $data['internalDate'] ?? null,
                ],
            ];

            // Only set the sender fields from inbound messages (the external person).
            // For outbound messages, preserve existing sender data on the thread.
            if ($direction === EmailMessage::DIRECTION_INBOUND) {
                $threadData['from_email'] = $fromEmail;
                $threadData['from_name'] = $fromName;
            }

            $thread = EmailThread::firstOrCreate(
                [
                    'gmail_account_id' => $this->account->id,
                    'gmail_thread_id' => $threadId,
                ],
                array_merge($threadData, [
                    'from_email' => $fromEmail,
                    'from_name' => $fromName,
                ]),
            );

            // If thread already existed and this is an inbound message,
            // update the sender info to the external person.
            if (!$thread->wasRecentlyCreated && $direction === EmailMessage::DIRECTION_INBOUND) {
                $thread->update([
                    'from_email' => $fromEmail,
                    'from_name' => $fromName,
                ]);
            }

            // Extract message body. Gmail nests the body in the payload parts.
            $bodyText = $this->extractBody($data['payload'] ?? [], 'text/plain');
            $bodyHtml = $this->extractBody($data['payload'] ?? [], 'text/html');

            // firstOrCreate prevents duplicate messages. If this exact message
            // was already stored (duplicate Pub/Sub notification), this is a no-op.
            EmailMessage::firstOrCreate(
                ['gmail_message_id' => $messageId],
                [
                    'email_thread_id' => $thread->id,
                    'direction' => $direction,
                    'body_text' => $bodyText,
                    'body_html' => $bodyHtml,
                    'received_at' => isset($data['internalDate'])
                        ? \Carbon\Carbon::createFromTimestampMs($data['internalDate'])
                        : now(),
                ],
            );

            // Only classify new, unclassified, inbound threads.
            // Outbound messages (our own replies) don't need classification.
            // Already-classified threads (from a previous sync) are skipped.
            if ($direction === EmailMessage::DIRECTION_INBOUND && !$thread->isClassified()) {
                ClassifyEmailJob::dispatch($thread);
            }
        } catch (\Exception $e) {
            // WHY we catch and log instead of rethrowing:
            // A single bad message shouldn't stop the entire sync. If message
            // #3 of 10 fails to parse, we still want messages #4-10 processed.
            // The failed message is logged for investigation.
            Log::error('Failed to process message', [
                'message_id' => $messageId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Extract the body content from a Gmail message payload.
     *
     * PURPOSE:
     * Gmail messages have a nested multipart structure. The body can be
     * directly on the payload (simple messages) or inside parts[]/parts[]
     * (multipart messages). This method walks the tree to find the right
     * MIME type.
     *
     * WHY recursive: Multipart emails can nest several levels deep.
     * A multipart/mixed can contain a multipart/alternative which contains
     * text/plain and text/html. We need to walk all levels.
     */
    private function extractBody(array $payload, string $mimeType): ?string
    {
        // Check if the body is directly on this payload part.
        if (($payload['mimeType'] ?? '') === $mimeType && !empty($payload['body']['data'])) {
            // WHY base64url decode: Gmail's API returns body data in URL-safe
            // base64 encoding (+ replaced with -, / replaced with _). PHP's
            // base64_decode expects standard base64, so we convert first.
            return base64_decode(strtr($payload['body']['data'], '-_', '+/'));
        }

        // Recursively search through parts for the target MIME type.
        foreach ($payload['parts'] ?? [] as $part) {
            $result = $this->extractBody($part, $mimeType);
            if ($result !== null) {
                return $result;
            }
        }

        return null;
    }

    /**
     * Update the account's history ID for the next incremental sync.
     */
    private function updateHistoryId(): void
    {
        try {
            $response = Http::withToken($this->account->access_token)
                ->timeout(10)
                ->get('https://gmail.googleapis.com/gmail/v1/users/me/profile');

            if ($response->successful()) {
                $this->account->update([
                    'google_history_id' => $response->json('historyId'),
                ]);
            }
        } catch (\Exception $e) {
            // Non-fatal: the next sync will use the old history ID, which
            // means it will re-fetch some messages. The upsert pattern
            // handles the duplicates gracefully.
            Log::warning('Failed to update history ID', [
                'account_id' => $this->account->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Handle Gmail API errors with appropriate responses.
     *
     * WHY separate error handling:
     * Different HTTP status codes require different actions:
     *   - 401: Token expired, needs refresh (not a retry scenario).
     *   - 429: Rate limited, retry after a delay.
     *   - 500+: Server error, retry makes sense.
     * Treating all errors the same would waste retries on non-retriable errors.
     */
    private function handleApiError(int $status): void
    {
        if ($status === 401) {
            // Token expired or revoked. Deactivate the account so we stop
            // trying to sync it. The user will need to re-authenticate.
            $this->account->update(['is_active' => false]);
            Log::warning('Gmail token expired, account deactivated', [
                'account_id' => $this->account->id,
            ]);
            return;
        }

        // For retriable errors (429, 5xx), let the job retry mechanism handle it.
        // Throwing causes the job to go back on the queue with backoff.
        if ($status === 429 || $status >= 500) {
            throw new \RuntimeException("Gmail API error: HTTP {$status}");
        }
    }
}
