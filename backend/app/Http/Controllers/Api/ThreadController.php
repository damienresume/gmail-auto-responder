<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EmailThread;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ThreadController
 *
 * PURPOSE:
 * Serves email thread data to the Next.js dashboard. The dashboard calls
 * these endpoints to display the thread list (with classifications) and
 * individual thread detail (with messages and draft).
 *
 * WHY a separate controller from DraftController:
 * Threads and drafts are different resources with different access patterns.
 * The thread list is the dashboard's main view (high traffic, paginated).
 * Draft operations (approve, send, discard) are user-initiated actions
 * (lower traffic, write-heavy). Separating them follows REST conventions
 * and keeps each controller focused on one resource.
 *
 * SECURITY:
 * All endpoints require authentication (enforced by the auth:sanctum
 * middleware on the route group). Users can only see threads belonging
 * to their own Gmail accounts. The whereHas clause ensures a user cannot
 * access another user's threads by guessing IDs.
 */
class ThreadController extends Controller
{
    /**
     * List email threads for the authenticated user.
     *
     * PURPOSE:
     * Powers the dashboard's main thread list. Supports filtering by
     * classification and Gmail account, with pagination for performance.
     *
     * WHY eager loading (with(['latestDraft', 'gmailAccount'])):
     * Without eager loading, displaying 20 threads would trigger 40 extra
     * queries (one for each thread's draft + one for each thread's account).
     * This is the N+1 problem. Eager loading batches these into 2 queries
     * total, regardless of how many threads are returned.
     *
     * WHY cursor pagination instead of offset pagination:
     * Offset pagination (LIMIT 20 OFFSET 1000) becomes slower as pages
     * increase because PostgreSQL must scan and discard the first 1000 rows.
     * Cursor pagination (WHERE id < last_seen_id LIMIT 20) is consistently
     * fast because it uses the primary key index directly. For a dashboard
     * that could have thousands of threads, cursor pagination scales better.
     */
    public function index(Request $request): JsonResponse
    {
        $query = EmailThread::query()
            ->whereHas('gmailAccount', function ($q) use ($request) {
                $q->where('user_id', $request->user()->id);
            })
            ->with(['latestDraft', 'gmailAccount:id,gmail_email'])
            // Add the most recent message's received_at as last_message_at
            // so the frontend can display the actual email date, not our
            // processing date.
            ->addSelect(['*',
                \Illuminate\Support\Facades\DB::raw('(
                    SELECT received_at FROM email_messages
                    WHERE email_messages.email_thread_id = email_threads.id
                    ORDER BY received_at DESC LIMIT 1
                ) as last_message_at'),
            ]);

        // Optional filter: show only threads with a specific classification.
        // Used by dashboard tabs (Interested, Not Interested, Meeting Request, etc.).
        if ($request->has('classification')) {
            $query->byClassification($request->input('classification'));
        }

        // Optional filter: show threads from a specific Gmail account.
        // Used when a user has multiple connected accounts and wants to
        // focus on one inbox at a time.
        if ($request->has('account_id')) {
            $query->forAccount((int) $request->input('account_id'));
        }

        $threads = $query->orderByDesc('last_message_at')->cursorPaginate(20);

        return response()->json($threads);
    }

    /**
     * Show a single thread with its messages and draft.
     *
     * PURPOSE:
     * Powers the dashboard's thread detail view. Shows the full conversation
     * history (all messages) and the latest draft reply for review.
     *
     * WHY load messages and latestDraft together:
     * The detail view displays the conversation thread on the left and the
     * draft reply on the right. Loading both in one request avoids a second
     * round-trip from the frontend, reducing perceived latency.
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $thread = EmailThread::where('id', $id)
            ->whereHas('gmailAccount', function ($q) use ($request) {
                $q->where('user_id', $request->user()->id);
            })
            ->with(['messages', 'latestDraft', 'gmailAccount:id,gmail_email'])
            ->first();

        if (!$thread) {
            return response()->json(['message' => 'Thread not found'], 404);
        }

        return response()->json($thread);
    }
}
