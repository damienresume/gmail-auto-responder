<?php

namespace App\Jobs;

use App\Models\Draft;
use App\Models\EmailThread;
use App\Services\Llm\LlmServiceInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * GenerateDraftJob
 *
 * PURPOSE:
 * Takes a classified email thread, asks the LLM to write a reply, stores the
 * reply in our database, and creates a draft in the user's Gmail inbox. This
 * is the final step in the pipeline:
 *   FetchNewEmailsJob -> ClassifyEmailJob -> GenerateDraftJob
 *
 * WHY a separate job from ClassifyEmailJob:
 *   - Different external dependency: ClassifyEmailJob only calls the LLM.
 *     This job calls the LLM and the Gmail API (drafts.create). If the
 *     Gmail API is down but the LLM works, we can still generate the reply
 *     text and create it in Gmail later (retry).
 *
 *   - Different queue priority: Draft generation is the lowest priority task
 *     because the user hasn't asked for it yet, they'll review it later in
 *     the dashboard. Email fetching and classification are higher priority
 *     because they determine what shows up in the dashboard at all.
 *
 *   - Allows parallel processing: While one worker generates a draft for
 *     thread A, another worker can classify thread B. If all three steps
 *     were in one job, they'd run sequentially.
 *
 * HOW it works:
 *   1. Loads the thread's conversation context (all messages).
 *   2. Calls LlmServiceInterface::generateReply() with the email body and
 *      classification so the LLM can tailor its tone.
 *   3. Creates a Draft model record in our database (status: 'generated').
 *   4. Calls Gmail's drafts.create API to create the draft in the user's
 *      Gmail inbox. Stores the returned gmail_draft_id on our record.
 *   5. The user sees the draft in both Gmail and our dashboard.
 *
 * QUEUE: 'drafts', lowest priority, 2 workers.
 */
class GenerateDraftJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public array $backoff = [15, 60, 180];

    public function __construct(
        private readonly EmailThread $thread,
    ) {
        $this->onQueue('drafts');
    }

    public function handle(LlmServiceInterface $llm): void
    {
        // Guard: don't generate a draft if one already exists for this thread.
        // This handles duplicate dispatches from ClassifyEmailJob retries.
        $existingDraft = $this->thread->latestDraft;
        if ($existingDraft && $existingDraft->status !== Draft::STATUS_DISCARDED) {
            Log::info('Draft already exists for thread, skipping', [
                'thread_id' => $this->thread->id,
                'draft_id' => $existingDraft->id,
            ]);
            return;
        }

        // Build conversation context from all messages in the thread.
        // The LLM generates a better reply when it can see the full
        // conversation, not just the latest message.
        $messages = $this->thread->messages()->get();
        $conversationBody = $messages
            ->map(fn ($msg) => "[{$msg->direction}] {$msg->body_text}")
            ->implode("\n\n---\n\n");

        Log::info('Generating draft reply', [
            'thread_id' => $this->thread->id,
            'classification' => $this->thread->classification,
        ]);

        // Call the LLM to generate a reply. The classification is passed
        // so the LLM can adjust its tone (enthusiastic for "interested",
        // cautious for "unclear", scheduling-focused for "meeting_request").
        $result = $llm->generateReply(
            subject: $this->thread->subject,
            body: $conversationBody,
            classification: $this->thread->classification ?? 'unclear',
        );

        // Store the draft in our database first. Even if the Gmail API
        // call fails next, the generated text is preserved for retry.
        $draft = Draft::create([
            'email_thread_id' => $this->thread->id,
            'body_text' => $result->bodyText,
            'body_html' => $result->bodyHtml,
            'status' => Draft::STATUS_GENERATED,
            'revision' => 1,
        ]);

        // Create the draft in the user's Gmail inbox via the API.
        // This step is optional, if it fails, the draft still exists
        // in our database and can be pushed to Gmail later.
        $this->createGmailDraft($draft, $result->subject);

        Log::info('Draft generated successfully', [
            'thread_id' => $this->thread->id,
            'draft_id' => $draft->id,
        ]);
    }

    /**
     * Create a draft in the user's Gmail inbox via the Gmail API.
     *
     * PURPOSE:
     * After the LLM generates a reply, we push it to Gmail so the user
     * can see it in their Drafts folder (not just in our dashboard).
     * This is a convenience, the primary draft lives in our database.
     *
     * WHY this is a separate method:
     * Gmail API interaction is isolated so it can fail independently of
     * the LLM call. If Gmail's API is down, we've already saved the draft
     * text in our database. The gmail_draft_id will be null, which we can
     * retry or handle when the user clicks "Send" in the dashboard.
     *
     * WHY base64url encoding:
     * The Gmail API requires the raw email message in RFC 2822 format,
     * encoded as URL-safe base64. This is Gmail's standard format for
     * message content in API requests.
     */
    private function createGmailDraft(Draft $draft, string $subject): void
    {
        try {
            $account = $this->thread->gmailAccount;

            if (!$account || !$account->is_active) {
                Log::warning('Gmail account inactive, skipping Gmail draft creation', [
                    'thread_id' => $this->thread->id,
                ]);
                return;
            }

            // Build a minimal RFC 2822 email message.
            // To: is the original sender (we're replying to them).
            // From: is the Gmail account owner.
            // The thread ID tells Gmail to group this draft with the original thread.
            $rawMessage = implode("\r\n", [
                "To: {$this->thread->from_email}",
                "From: {$account->gmail_email}",
                "Subject: {$subject}",
                "Content-Type: text/html; charset=UTF-8",
                "",
                $draft->body_html,
            ]);

            // Gmail requires URL-safe base64 encoding for raw message content.
            $encodedMessage = rtrim(strtr(base64_encode($rawMessage), '+/', '-_'), '=');

            $response = Http::withToken($account->access_token)
                ->timeout(15)
                ->post('https://gmail.googleapis.com/gmail/v1/users/me/drafts', [
                    'message' => [
                        'raw' => $encodedMessage,
                        'threadId' => $this->thread->gmail_thread_id,
                    ],
                ]);

            if ($response->successful()) {
                // Store the Gmail draft ID so we can reference it when the
                // user clicks "Send" (we call drafts.send with this ID).
                $draft->update([
                    'gmail_draft_id' => $response->json('id'),
                ]);
            } else {
                // Non-fatal: the draft text is saved in our database.
                // The user can still review and send it from the dashboard.
                // We can retry pushing to Gmail later if needed.
                Log::warning('Failed to create Gmail draft', [
                    'draft_id' => $draft->id,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
            }
        } catch (\Exception $e) {
            Log::warning('Gmail draft creation exception', [
                'draft_id' => $draft->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
