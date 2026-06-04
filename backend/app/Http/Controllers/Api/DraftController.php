<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Draft;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * DraftController
 *
 * PURPOSE:
 * Manages the draft reply lifecycle from the dashboard. Users can:
 *   - List all drafts pending review
 *   - Edit a draft's text before sending
 *   - Approve a draft (marks it ready to send)
 *   - Send a draft (pushes it to Gmail via the API)
 *   - Discard a draft (marks it as rejected)
 *
 * WHY these endpoints map to the draft lifecycle:
 * Each endpoint corresponds to one user
 * action in the dashboard UI:
 *   - "Needs Review" tab → index() with status=generated
 *   - "Edit" button → update()
 *   - "Approve" button → approve()
 *   - "Send" button → send()
 *   - "Discard" button → discard()
 *
 * SECURITY:
 * All endpoints require authentication. The authorization check ensures
 * users can only manage drafts for their own threads. Status transitions
 * are guarded by the Draft model's methods (e.g., can't approve an
 * already-sent draft), returning 409 Conflict for invalid transitions.
 */
class DraftController extends Controller
{
    /**
     * List drafts for the authenticated user.
     *
     * PURPOSE:
     * Powers the dashboard's draft review queue. Defaults to showing
     * drafts with status "generated" (pending review), but can filter
     * by any status for the "Sent" and "All" tabs.
     *
     * WHY eager load emailThread:
     * Each draft in the list shows the original email's subject and sender.
     * Without eager loading, displaying 20 drafts would fire 20 extra
     * queries to fetch each thread. Eager loading reduces this to 1 query.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Draft::query()
            ->whereHas('emailThread.gmailAccount', function ($q) use ($request) {
                $q->where('user_id', $request->user()->id);
            })
            ->with(['emailThread:id,subject,from_email,from_name,classification']);

        // Default to pending review; the dashboard's primary view.
        $status = $request->input('status', Draft::STATUS_GENERATED);
        $query->where('status', $status);

        $drafts = $query->latest()->cursorPaginate(20);

        return response()->json($drafts);
    }

    /**
     * Update a draft's body text (user edits before sending).
     *
     * PURPOSE:
     * The user reads the LLM generated reply, makes corrections or
     * adjustments, and saves their edits. This updates both the plain
     * text and HTML versions in the database.
     *
     * WHY we regenerate HTML from the edited text:
     * The user edits plain text in the dashboard. We regenerate the HTML
     * to keep both versions in sync. If we let them go out of sync, the email
     * sent via Gmail (which uses HTML) wouldn't match what the user
     * previewed (which shows plain text).
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $draft = $this->findAuthorizedDraft($request, $id);

        if (!$draft) {
            return response()->json(['message' => 'Draft not found'], 404);
        }

        if (!$draft->isEditable()) {
            return response()->json(['message' => 'Draft cannot be edited in its current status'], 409);
        }

        $validated = $request->validate([
            'body_text' => 'required|string|min:1',
        ]);

        // Regenerate HTML from the edited plain text.
        // Same XSS-safe conversion used in DraftReplyResult DTO.
        $bodyHtml = '<p>' . nl2br(htmlspecialchars($validated['body_text'], ENT_QUOTES, 'UTF-8')) . '</p>';

        $draft->update([
            'body_text' => $validated['body_text'],
            'body_html' => $bodyHtml,
            'revision' => $draft->revision + 1,
        ]);

        return response()->json($draft->fresh());
    }

    /**
     * Approve a draft for sending.
     *
     * PURPOSE:
     * Marks the draft as reviewed and approved by the user. This is a
     * separate step from sending because the user might approve multiple
     * drafts at once, then send them in batch later.
     *
     * WHY 409 for invalid transitions:
     * HTTP 409 Conflict indicates the request conflicts with the current
     * state of the resource. "Approving an already-sent draft" is a state
     * conflict, not a client error (400) or authorization issue (403).
     */
    public function approve(Request $request, int $id): JsonResponse
    {
        $draft = $this->findAuthorizedDraft($request, $id);

        if (!$draft) {
            return response()->json(['message' => 'Draft not found'], 404);
        }

        if (!$draft->markAsApproved()) {
            return response()->json([
                'message' => 'Draft cannot be approved from status: ' . $draft->status,
            ], 409);
        }

        return response()->json($draft->fresh());
    }

    /**
     * Send an approved draft via the Gmail API.
     *
     * PURPOSE:
     * The final action in the pipeline. Takes an approved draft, sends it
     * through Gmail, and marks it as sent. This is the only point where
     * an email actually leaves the user's inbox.
     *
     * WHY we require approval before sending:
     * Defense in depth. Even if a bug in the frontend calls send() directly,
     * the Draft model's markAsSent() method rejects the transition unless
     * the status is "approved". The UI enforces approve -> send order,
     * and the backend independently enforces it too.
     */
    public function send(Request $request, int $id): JsonResponse
    {
        $draft = $this->findAuthorizedDraft($request, $id);

        if (!$draft) {
            return response()->json(['message' => 'Draft not found'], 404);
        }

        if ($draft->status !== Draft::STATUS_APPROVED) {
            return response()->json([
                'message' => 'Draft must be approved before sending. Current status: ' . $draft->status,
            ], 409);
        }

        // If the draft has a Gmail draft ID, send it via Gmail's drafts.send API.
        // If not (Gmail draft creation failed earlier), create and send directly.
        $sent = $this->sendViaGmail($draft);

        if (!$sent) {
            return response()->json(['message' => 'Failed to send via Gmail API'], 502);
        }

        return response()->json($draft->fresh());
    }

    /**
     * Discard a draft (user decided not to send it).
     *
     * PURPOSE:
     * The user reviews the LLM's reply and decides it's not appropriate.
     * Discarding marks it as rejected so it disappears from the review
     * queue. The draft data is preserved for audit purposes.
     */
    public function discard(Request $request, int $id): JsonResponse
    {
        $draft = $this->findAuthorizedDraft($request, $id);

        if (!$draft) {
            return response()->json(['message' => 'Draft not found'], 404);
        }

        if (!$draft->discard()) {
            return response()->json([
                'message' => 'Draft cannot be discarded from status: ' . $draft->status,
            ], 409);
        }

        return response()->json($draft->fresh());
    }

    /**
     * Find a draft that belongs to the authenticated user.
     *
     * PURPOSE:
     * Authorization check shared by all endpoints. Ensures a user cannot
     * manage drafts for threads they don't own by guessing draft IDs.
     * The check walks the relationship chain: draft -> thread -> account -> user.
     */
    private function findAuthorizedDraft(Request $request, int $id): ?Draft
    {
        return Draft::where('id', $id)
            ->whereHas('emailThread.gmailAccount', function ($q) use ($request) {
                $q->where('user_id', $request->user()->id);
            })
            ->first();
    }

    /**
     * Send a draft via the Gmail API.
     *
     * PURPOSE:
     * Handles the actual Gmail API call to send the draft. If the draft
     * has a gmail_draft_id (from GenerateDraftJob), we use drafts.send.
     * Otherwise, we create and send a new message directly.
     *
     * WHY drafts.send instead of messages.send:
     * drafts.send removes the draft from the user's Drafts folder after
     * sending and properly threads the reply with the original conversation.
     * messages.send would leave an orphaned draft and might not thread correctly.
     */
    private function sendViaGmail(Draft $draft): bool
    {
        try {
            $thread = $draft->emailThread;
            $account = $thread->gmailAccount;

            if (!$account || !$account->is_active) {
                Log::warning('Cannot send draft: Gmail account inactive', [
                    'draft_id' => $draft->id,
                ]);
                return false;
            }

            $response = null;

            // Try drafts.send first if we have a Gmail draft ID.
            if ($draft->gmail_draft_id) {
                $response = Http::withToken($account->access_token)
                    ->timeout(15)
                    ->post("https://gmail.googleapis.com/gmail/v1/users/me/drafts/send", [
                        'id' => $draft->gmail_draft_id,
                    ]);
            }

            // Fall back to messages.send if no Gmail draft ID exists or
            // if drafts.send returned 404 (draft was deleted from Gmail,
            // e.g., after reconnecting the account or manual deletion).
            if (!$response || $response->status() === 404) {
                $rawMessage = implode("\r\n", [
                    "To: {$thread->from_email}",
                    "From: {$account->gmail_email}",
                    "Subject: Re: {$thread->subject}",
                    "Content-Type: text/html; charset=UTF-8",
                    "",
                    $draft->body_html,
                ]);

                $encodedMessage = rtrim(strtr(base64_encode($rawMessage), '+/', '-_'), '=');

                $response = Http::withToken($account->access_token)
                    ->timeout(15)
                    ->post("https://gmail.googleapis.com/gmail/v1/users/me/messages/send", [
                        'raw' => $encodedMessage,
                        'threadId' => $thread->gmail_thread_id,
                    ]);
            }

            if ($response->successful()) {
                $draft->markAsSent($draft->gmail_draft_id);
                Log::info('Draft sent via Gmail', [
                    'draft_id' => $draft->id,
                    'thread_id' => $thread->id,
                ]);
                return true;
            }

            Log::error('Gmail send failed', [
                'draft_id' => $draft->id,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            return false;
        } catch (\Exception $e) {
            Log::error('Gmail send exception', [
                'draft_id' => $draft->id,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }
}
