<?php

/**
 * API Routes
 *
 * PURPOSE:
 * Defines all HTTP endpoints the Next.js dashboard and Google Pub/Sub
 * call. Routes are split into three groups based on authentication needs:
 *
 *   1. Public (no auth): Gmail webhook Pub/Sub can't send auth headers.
 *   2. Authenticated: Thread and draft endpoints requires a logged-in user.
 *   3. Web (session auth): OAuth flow uses browser redirects, not API tokens.
 *
 * SECURITY:
 *   - auth:sanctum middleware on all dashboard endpoints ensures only
 *     authenticated users can access thread and draft data.
 *   - The webhook is intentionally public but validates notifications
 *     by matching the email address against our database.
 *   - OAuth routes use web middleware (session + CSRF) because they
 *     involve browser redirects, not API calls.
 */

use App\Http\Controllers\Api\DraftController;
use App\Http\Controllers\Api\GmailWebhookController;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\ThreadController;
use Illuminate\Support\Facades\Route;

// Health checks (public, no auth - used by load balancers and K8s probes)
Route::get('/health', [HealthController::class, 'check']);
Route::get('/health/gmail', [HealthController::class, 'gmail']);

// -------------------------------------------------------------------------
// Public: Gmail Pub/Sub Webhook
// -------------------------------------------------------------------------
// WHY: Google Pub/Sub sends push notifications as HTTP
// POST requests. It cannot include authentication tokens or session cookies.
// The webhook validates requests by checking the email address in the
// notification payload against our database of active Gmail accounts.
// -------------------------------------------------------------------------
Route::post('/gmail/webhook', [GmailWebhookController::class, 'handle'])
    ->name('gmail.webhook');

// -------------------------------------------------------------------------
// Authenticated: Dashboard API Endpoints
// -------------------------------------------------------------------------
// WHY auth:sanctum: These endpoints serve sensitive data (email content,
// classification results, draft replies) and perform actions (approve,
// send, discard). Every request must come from an authenticated user.
// Sanctum supports both SPA cookie auth (for the Next.js dashboard) and
// API token auth (for future mobile apps or integrations).
// -------------------------------------------------------------------------
Route::middleware('auth:sanctum')->group(function () {

    // Thread endpoints — the dashboard's primary data source.
    Route::get('/threads', [ThreadController::class, 'index'])->name('threads.index');
    Route::get('/threads/{id}', [ThreadController::class, 'show'])->name('threads.show');

    // Draft endpoints — the human-in-the-loop control layer.
    Route::get('/drafts', [DraftController::class, 'index'])->name('drafts.index');
    Route::put('/drafts/{id}', [DraftController::class, 'update'])->name('drafts.update');
    Route::post('/drafts/{id}/approve', [DraftController::class, 'approve'])->name('drafts.approve');
    Route::post('/drafts/{id}/send', [DraftController::class, 'send'])->name('drafts.send');
    Route::post('/drafts/{id}/discard', [DraftController::class, 'discard'])->name('drafts.discard');
});
