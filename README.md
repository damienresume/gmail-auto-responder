# Gmail Auto-Responder

A scalable, AI-powered Gmail auto-responder built with Laravel, Next.js, and a swappable LLM backend (Groq, Ollama, or stub). Connects to Gmail accounts via OAuth, classifies incoming emails, and generates intelligent draft replies for human review.

---

## Table of Contents

- [High-Level Architecture](#high-level-architecture)
- [System Design Decisions](#system-design-decisions)
- [Data Model](#data-model)
- [Gmail Integration](#gmail-integration)
- [Queue Architecture](#queue-architecture)
- [LLM Pipeline](#llm-pipeline)
- [Idempotency, Retries & Failure Handling](#idempotency-retries--failure-handling)
- [Scaling to Many Accounts](#scaling-to-many-accounts)
- [Observability](#observability)
- [Deploy Story](#deploy-story)
- [What I'd Build Next](#what-id-build-next)
- [What I Deliberately Skipped](#what-i-deliberately-skipped)
- [Getting Started](#getting-started)

---

## High-Level Architecture

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                              GOOGLE CLOUD                                   │
│                                                                             │
│   ┌──────────────┐          ┌───────────────────┐                           │
│   │  Gmail Inbox │─────────▶│  Google Cloud     │                           │
│   │              │ new      │  Pub/Sub          │                           │
│   └──────────────┘ email    └─────────┬─────────┘                           │
│                                       │ push notification                   │
└───────────────────────────────────────┼─────────────────────────────────────┘
                                        │
                                        ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│                           LARAVEL BACKEND                                   │
│                                                                             │
│   ┌──────────────────┐    ┌──────────────────┐    ┌──────────────────┐      │
│   │  Pub/Sub Webhook │───▶│  Laravel Horizon │───▶│ FetchNewEmailsJob│      │
│   │  POST /api/gmail │    │  (Redis Queues)  │    │                  │      │
│   │  webhook         │    └──────────────────┘    └────────┬─────────┘      │
│   └──────────────────┘                                      │               │
│                                                             ▼               │
│                                                  ┌──────────────────┐       │
│                                                  │ ClassifyEmailJob │       │
│   ┌───────────────────┐                          │ • Send to LLM    │       │
│   │  REST API         │                          │ • Store result   │       │
│   │  GET /api/threads │                          └────────┬─────────┘       │
│   │  GET /api/drafts  │                                   │                 │
│   │  POST /api/drafts │                                   ▼                 │
│   │    /:id/send      │                          ┌──────────────────┐       │
│   └───────────────────┘                          │ GenerateDraftJob │       │
│                                                  │ • Send to LLM    │       │
│                                                  │ • Save Gmail     │       │
│                                                  │   draft          │       │
│                                                  └──────────────────┘       │
└─────────────────────────────────────────────────────────────────────────────┘
         │                          │                         │
         ▼                          ▼                         ▼
┌────────────────┐    ┌───────────────────┐    ┌──────────────────────────────┐
│  PostgreSQL    │    │  Redis 7          │    │  LLM Service (Adapter)       │
│  15            │    │  Queue + Cache    │    │                              │
│                │    │  Token storage    │    │  ┌────────┐  ┌───────────┐   │
│  • users       │    │  Rate limiting    │    │  │  Groq  │  │  Ollama   │   │
│  • gmail_accts │    │                   │    │  │ (cloud)│  │  (local)  │   │
│  • threads     │    │                   │    │  │ FREE   │  │  FREE     │   │
│  • messages    │    │                   │    │  └────────┘  └───────────┘   │
│  • drafts      │    │                   │    │       ┌───────────┐          │
│                │    │                   │    │       │   Stub    │          │
│                │    │                   │    │       │ (no LLM)  │          │
│                │    │                   │    │       └───────────┘          │
└────────────────┘    └───────────────────┘    └──────────────────────────────┘
                                                            │
                                                            │
┌─────────────────────────────────────────────────────────────────────────────┐
│                        NEXT.JS 15 DASHBOARD                                 │
│                                                                             │
│   ┌──────────────┐  ┌──────────────┐  ┌──────────────┐  ┌──────────────┐    │
│   │ Thread List  │  │ Classifi-    │  │ Draft Editor │  │ Approve /    │    │
│   │              │  │ cation View  │  │              │  │ Edit / Send  │    │
│   └──────────────┘  └──────────────┘  └──────────────┘  └──────────────┘    │
└─────────────────────────────────────────────────────────────────────────────┘
```

PNG version: [docs/diagrams/architecture.png](docs/diagrams/architecture.png)

**Data flow for a single incoming email:**

1. New email arrives in the user's Gmail inbox.
2. Google Pub/Sub pushes a notification to our webhook endpoint (`POST /api/gmail/webhook`).
3. The webhook dispatches a `FetchNewEmailsJob` onto the `gmail-ingest` Redis queue.
4. Horizon picks up the job, calls Gmail's `history.list` API to get new message IDs, then calls `messages.get` to fetch the full email content. Both calls use the account's encrypted OAuth token.
5. A `ClassifyEmailJob` is dispatched onto the `classification` queue. The job sends the email subject + body to the LLM (Groq, Ollama, or stub) and receives a JSON response with `classification`, `confidence`, and `reasoning`.
6. The classification is stored in PostgreSQL. If the result is `interested`, `meeting_request`, or `unclear`, a `GenerateDraftJob` is dispatched onto the `drafts` queue. Emails classified as `not_interested` stop here - no draft is generated.
7. The draft job sends the email + classification to the LLM for reply generation. The generated reply is saved as a Gmail draft via the Gmail API (`drafts.create`) and stored in the `drafts` table.
8. The user opens the Next.js dashboard at `http://localhost:3000`, sees the thread with its classification and draft, and can approve, edit, or send.

---

## System Design Decisions

### Webhook (Pub/Sub Push) vs Polling

**Decision: Google Cloud Pub/Sub push notifications.**

| Approach | Latency | Scale Cost | Complexity |
|---|---|---|---|
| Polling (cron every N seconds) | 1–60s delay depending on interval | O(n) API calls per interval where n = connected accounts | Low |
| Gmail Push via Pub/Sub | Near real-time (~1–3s) | O(1) per incoming email - Google pushes to us | Medium |

**Why polling doesn't scale - the math:**

1,000 accounts × 2 API calls per poll (`users.messages.list` at 5 quota units + `users.messages.get` at 5 quota units) × 2,880 polls/day (every 30 seconds) = **5,760,000 API calls per day** consuming **57,600,000 quota units**. Most of those calls return nothing because the inbox hasn't changed.

With Pub/Sub, an account that receives 10 emails per day costs exactly 10 notifications - not 2,880 empty polls. Idle inboxes cost zero.

**Tradeoff acknowledged:** Pub/Sub requires a Google Cloud project with a Pub/Sub topic and subscription configured. This adds setup complexity, but eliminates the need for a cron scheduler and scales to any number of accounts without increasing API call volume.

### Draft vs Auto-Send

**Decision: Save as Gmail draft. User approves before sending.**

Four reasons for this decision:

1. **AI hallucination risk** - LLMs can generate factually incorrect or contextually inappropriate responses. A bad auto-sent reply is unrecoverable - you can't unsend an email.
2. **Professional communication is high-stakes** - one wrong tone in a reply can damage a relationship. Human review before sending is non-negotiable.
3. **Human-in-the-loop control** - the dashboard becomes the control layer where users review, edit, and approve before anything is sent. This builds trust in the system.
4. **Minimal additional API cost** - converting a saved draft to a sent email is a single Gmail API call (`drafts.send` at 25 quota units) triggered when the user clicks "Approve" in the dashboard.

### LLM Provider (Adapter Pattern)

**Decision: Swappable LLM backend using the Adapter Pattern - Groq (default), Ollama (alternative), and Stub (fallback).**

The `LlmServiceInterface` defines the contract. Three implementations are provided:

| Provider | Speed | Cost | Setup Required | Best For |
|---|---|---|---|---|
| **Groq** (default) | ~0.3s per call | $0 (free tier: 30 req/min) | Free API key at [console.groq.com](https://console.groq.com) | Fast cloud inference, demo, code review |
| **Ollama** (alternative) | 5–15s per call (CPU) | $0 (runs locally) | None - runs in Docker | Offline use, no signup needed |
| **Stub** (fallback) | Instant | $0 | None | Code review without any LLM running |

**Why Groq as default:** Groq uses custom LPU (Language Processing Unit) hardware purpose-built for LLM inference. It runs Llama 3.1 8B at ~800 tokens/second - roughly 10–50x faster than GPU-based cloud providers and 50–100x faster than Ollama on CPU. The free tier allows 30 requests per minute on Llama 3.1 8B, which supports processing ~43,000 emails per day (30 req/min × 1,440 min/day ÷ 1 classification call per email).

**Why Llama 3.1 8B:** It's the most capable open-source model at the 8B parameter size. It handles email classification (structured JSON output) and professional reply generation reliably. The 8B size runs on any machine via Ollama and is available on Groq's free tier. Larger models (70B, 405B) improve quality but aren't necessary for email classification where the task is constrained to 4 categories.

**Why the Adapter Pattern:** Building a provider-agnostic `LlmServiceInterface` means the LLM backend can be swapped without touching any business logic, queue jobs, or controller code. Adding a new provider (e.g. a self-hosted Python AI service) requires only a new class implementing the interface - zero changes to the rest of the codebase.

Set the provider in `.env`:
```
LLM_PROVIDER=groq      # Options: groq, ollama, stub
GROQ_API_KEY=your-key   # Required for groq, ignored for ollama/stub
```

### Queue & Worker

**Decision: Laravel Horizon + Redis.**

- **Horizon** is Laravel's first-party queue dashboard - job metrics, throughput, wait times, and failed jobs are all visible in a browser at `/horizon`. No third-party tools needed.
- **Redis** is already required for caching (token storage, rate limit counters), so using it for queues adds zero infrastructure.
- Queue jobs are individually retriable with configurable exponential backoff per queue.
- Three separate queues (ingest, classification, drafts) isolate failures so a slow LLM doesn't block email ingestion.

### Database

**Decision: PostgreSQL 15.**

The assignment specifies MySQL or PostgreSQL. PostgreSQL was chosen for:

- **Native JSON operators** (`jsonb`, `->>`, `@>`) - email metadata is stored as JSONB for flexible querying without schema migrations when new fields are added.
- **Partial indexes** - the `idx_drafts_pending` index only covers `status = 'generated'` rows, keeping the index small and fast for the dashboard's "pending review" query.
- **UPSERT with ON CONFLICT** - cleaner idempotency handling for duplicate Pub/Sub notifications compared to MySQL's `INSERT ... ON DUPLICATE KEY UPDATE`.
- **Better EXPLAIN ANALYZE** output for query optimization during development.

---

## Data Model

```
┌──────────────────────┐     ┌────────────────────────────┐     ┌────────────────────────────────┐
│        users         │     │       gmail_accounts       │     │          email_threads         │
├──────────────────────┤     ├────────────────────────────┤     ├────────────────────────────────┤
│ id              PK   │─────│ user_id             FK     │     │ id                    PK       │
│ name                 │ 1:N │ id                  PK     │─────│ gmail_account_id      FK       │
│ email           UK   │     │ gmail_email         UK     │ 1:N │ gmail_thread_id       UK       │
│ email_verified_at    │     │ access_token  (encrypted)  │     │ subject                        │
│ password             │     │ refresh_token (encrypted)  │     │ from_email                     │
│ remember_token       │     │ token_expires_at           │     │ from_name                      │
│ created_at           │     │ google_history_id          │     │ classification                 │
│ updated_at           │     │ is_active                  │     │ confidence_score               │
└──────────────────────┘     │ created_at                 │     │ classification_reasoning       │
                             │ updated_at                 │     │ classified_at                  │
                             └────────────────────────────┘     │ created_at                     │
                                                                │ updated_at                     │
                                                                └────────────────────────────────┘
                                                                         │
                                                            1:N          │          1:1
                                                      ┌─────────────────┴─────────────────┐
                                                      │                                   │
                                                      ▼                                   ▼
                                             ┌────────────────────────┐     ┌────────────────────────┐
                                             │     email_messages     │     │         drafts         │
                                             ├────────────────────────┤     ├────────────────────────┤
                                             │ id               PK    │     │ id              PK     │
                                             │ email_thread_id  FK    │     │ email_thread_id FK     │
                                             │ gmail_message_id UK    │     │ gmail_draft_id         │
                                             │ direction              │     │ body_text              │
                                             │ body_text              │     │ body_html              │
                                             │ body_html              │     │ status                 │
                                             │ received_at            │     │ revision               │
                                             │ created_at             │     │ sent_at                │
                                             │ updated_at             │     │ created_at             │
                                             └────────────────────────┘     │ updated_at             │
                                                                            └────────────────────────┘
```

PNG version: [docs/diagrams/data-model.png](docs/diagrams/data-model.png)

**Relationships:**

- **users → gmail_accounts** - One user can connect multiple Gmail accounts (one-to-many).
- **gmail_accounts → email_threads** - Each Gmail account has many threads (one-to-many).
- **email_threads → email_messages** - Each thread contains one or more messages (one-to-many).
- **email_threads → drafts** - Each thread has at most one active draft (one-to-one).

### Column Details

**`gmail_accounts.access_token` / `refresh_token` - encrypted at rest.**
Laravel's `encrypted` cast uses AES-256-CBC encryption with the `APP_KEY` from `.env` as the encryption key. Tokens are encrypted before every database write and decrypted only in memory when making Gmail API calls. If the database is compromised (SQL injection, backup leak, unauthorized access), the attacker gets ciphertext that is computationally infeasible to decrypt without the `APP_KEY`. The `APP_KEY` is never stored in the database - it lives only in the environment.

**`gmail_accounts.google_history_id` - Pub/Sub sync cursor.**
Gmail's [`history.list`](https://developers.google.com/gmail/api/reference/rest/v1/users.history/list) API uses a `historyId` to return only changes since the last sync. We store the latest one per account. When a Pub/Sub notification arrives, we call `history.list` with this ID to get exactly the new messages - no duplicates, no missed emails. If a `historyId` becomes stale (older than ~30 days), Gmail returns a `404` and we fall back to a full sync of the last 100 messages.

**`email_threads.gmail_thread_id` - unique constraint, idempotency key.**
Google Pub/Sub guarantees [at-least-once delivery](https://cloud.google.com/pubsub/docs/subscriber#at-least-once-delivery), meaning the same notification can arrive more than once. We use `gmail_thread_id` as a unique constraint. When a duplicate notification arrives, PostgreSQL's `ON CONFLICT DO NOTHING` skips the insert silently. No duplicate classifications, no duplicate drafts.

**`email_threads.classification` - enum with 5 values.**
Starts as `pending` when the thread is first ingested. Updated to `interested`, `not_interested`, `meeting_request`, or `unclear` when the LLM job completes. The dashboard shows "Processing..." for `pending` threads rather than hiding them.

**`email_threads.confidence_score` - float between 0.0 and 1.0.**
Returned by the LLM alongside the classification. Displayed on the dashboard so users can prioritize low-confidence classifications for manual review. A classification with confidence < 0.6 is flagged with a "Low confidence" badge.

**`drafts.status` - enum with 4 values.**
- `generated` - LLM created the draft, waiting for user review.
- `approved` - User clicked "Approve" but hasn't sent yet.
- `sent` - Draft was sent via Gmail API.
- `discarded` - User rejected the draft.

**`drafts.revision` - integer, starts at 1.**
Incremented each time the user edits the draft body in the dashboard. Provides an audit trail: revision 1 = AI-generated original, revision 2+ = user-edited.

### Indexes

```sql
-- Dashboard query: threads for a specific account, newest first.
-- Leading column is gmail_account_id because every dashboard query filters by account.
CREATE INDEX idx_threads_account_created
  ON email_threads (gmail_account_id, created_at DESC);

-- Dashboard filter: threads by classification for a specific account.
-- Covers the "Show me all interested emails" filter.
CREATE INDEX idx_threads_classification
  ON email_threads (gmail_account_id, classification);

-- Draft review queue: only pending drafts, newest first.
-- Partial index - only indexes rows where status = 'generated', keeping it small.
CREATE INDEX idx_drafts_pending
  ON drafts (created_at DESC) WHERE status = 'generated';

-- Idempotency: fast duplicate check on Pub/Sub notification arrival.
-- gmail_thread_id is already UNIQUE, but this makes the ON CONFLICT check explicit.
CREATE UNIQUE INDEX idx_threads_gmail_id
  ON email_threads (gmail_thread_id);
```

---

## Gmail Integration

### OAuth Flow

```
User clicks "Connect Gmail" in the dashboard
  │
  ▼
Laravel redirects to Google OAuth consent screen
  │  Requested scopes:
  │    • gmail.readonly   - read inbox messages
  │    • gmail.compose    - create drafts
  │    • gmail.modify     - send drafts
  │
  ▼
User grants permissions → Google redirects back with authorization code
  │
  ▼
Laravel exchanges code for access_token (valid 1 hour) + refresh_token (valid until revoked)
  │
  ▼
Both tokens are AES-256-CBC encrypted and stored in gmail_accounts table
  │
  ▼
Laravel calls Gmail API users.watch() to register Pub/Sub push notifications for this inbox
  │
  ▼
Account is now active - incoming emails trigger the classification pipeline
```

### Token Refresh

Google OAuth access tokens expire after **3,600 seconds (1 hour)** - this is set by Google and cannot be changed ([Google OAuth docs](https://developers.google.com/identity/protocols/oauth2#expiration)). The `GmailService` class checks `token_expires_at` before every API call. If the token expires within the next **5 minutes** (300-second buffer to prevent mid-request expiry), it calls Google's token endpoint with the refresh token to get a new access token, updates `access_token` and `token_expires_at` in the database, and proceeds. This is transparent to the calling code.

**Failure case:** If the refresh token is revoked (the user disconnected the app from their Google account settings), the refresh call returns a `400 invalid_grant` error. We catch this, mark the account as `is_active = false`, and surface a "Reconnect your Gmail" alert on the dashboard. No queue jobs are dispatched for inactive accounts.

### Pub/Sub Watch Setup

On successful OAuth, we call [`users.watch()`](https://developers.google.com/gmail/api/reference/rest/v1/users/watch) on the Gmail API to register a Pub/Sub push notification for the account's inbox. Google enforces a **maximum watch duration of 7 days** (604,800 seconds) - after that, push notifications stop silently with no error callback.

A scheduled Laravel command (`gmail:renew-watches`) runs daily via `schedule:run` and renews any watch expiring within the next **48 hours**, giving a 24-hour safety buffer in case one renewal fails due to a transient Google API error.

### Rate Limits

Gmail API uses a quota system measured in "units" per method call ([Gmail API quota docs](https://developers.google.com/gmail/api/reference/quota)):

| Gmail API Method | Quota Cost | When We Call It |
|---|---|---|
| `users.history.list` | 2 units | On every Pub/Sub notification - fetches changes since last sync |
| `users.messages.get` | 5 units | Once per new message - fetches full email content |
| `users.drafts.create` | 10 units | Once per generated draft - saves reply as Gmail draft |
| `users.drafts.send` | 25 units | Once per approved draft - sends the reply |

**Per incoming email (classification flow):** `history.list` (2) + `messages.get` (5) = **7 quota units.**
**Per draft save:** `drafts.create` = **10 quota units.**
**Per approved send:** `drafts.send` = **25 quota units.**

Google's per-user rate limit is **250 quota units per user per second**. Our design naturally stays within this because Horizon processes one job at a time per account - no parallel Gmail API calls for the same user. The `GmailService` also wraps every call in Laravel's `RateLimiter::for('gmail-api')` keyed by `gmail_account_id`, enforcing a ceiling of **200 units/second** with automatic 1-second backoff. The 50-unit buffer (250 limit − 200 ceiling) absorbs burst retries without hitting Google's hard limit.

---

## Queue Architecture

```
                    ┌──────────────────┐
                    │  Pub/Sub Webhook │
                    │  POST /api/gmail/│
                    │  webhook         │
                    └────────┬─────────┘
                             │ dispatch
                             ▼
              ┌──────────────────────────────┐
              │  Queue: gmail-ingest         │
              │  Priority: HIGH              │
              │  Workers: 3                  │
              ├──────────────────────────────┤
              │  FetchNewEmailsJob           │
              │  • Call history.list (2 qu)  │
              │  • Call messages.get (5 qu)  │
              │  • Store in email_messages   │
              └──────────────┬───────────────┘
                             │ dispatch
                             ▼
              ┌──────────────────────────────┐
              │  Queue: classification       │
              │  Priority: MEDIUM            │
              │  Workers: 2                  │
              ├──────────────────────────────┤
              │  ClassifyEmailJob            │
              │  • Send to LLM via adapter   │
              │  • Store classification      │
              │  • Dispatch draft if relevant│
              └──────────────┬───────────────┘
                             │ dispatch (only if interested,
                             │ meeting_request, or unclear)
                             ▼
              ┌──────────────────────────────┐
              │  Queue: drafts               │
              │  Priority: LOW               │
              │  Workers: 2                  │
              ├──────────────────────────────┤
              │  GenerateDraftJob            │
              │  • Send to LLM via adapter   │
              │  • Save Gmail draft (10 qu)  │
              │  • Store in drafts table     │
              └──────────────────────────────┘
```

PNG version: [docs/diagrams/queue-architecture.png](docs/diagrams/queue-architecture.png)

### Why Three Separate Queues

| Queue | Priority | Workers | Purpose |
|---|---|---|---|
| `gmail-ingest` | High | 3 | Fetch new emails from Gmail API. Must be fast to keep the `historyId` sync cursor current. A stale cursor (older than ~30 days) causes Gmail to return `404 historyId not found`, requiring a full resync of the last 100 messages. |
| `classification` | Medium | 2 | LLM classification. Groq averages ~0.3s per call; Ollama averages 5–15s on CPU. Not user-facing latency - runs in the background. |
| `drafts` | Low | 2 | LLM draft generation. Slightly longer than classification because the output is longer (~100–200 words vs ~20 words). The user only sees the result when they open the dashboard. |

**Why not one queue?** If draft generation is slow (Ollama on CPU, or Groq rate limit hit), it would block email ingestion. With separate queues, a user's inbox keeps syncing even if the LLM is temporarily slow. Horizon makes this trivial - each queue is a separate worker pool with independent retry configuration.

### Horizon Configuration

```php
// config/horizon.php
'environments' => [
    'production' => [
        'gmail-ingest' => [
            'connection' => 'redis',
            'queue' => 'gmail-ingest',
            'processes' => 3,        // 3 parallel workers
            'tries' => 3,            // retry up to 3 times
            'backoff' => [10, 60, 300],  // 10s → 60s → 5 min
            // Why these intervals: Gmail API errors are often transient
            // (network blip, token refresh race). Aggressive first retry (10s).
            // 5 min max because a stale historyId becomes invalid after ~1 hour.
        ],
        'classification' => [
            'connection' => 'redis',
            'queue' => 'classification',
            'processes' => 2,
            'tries' => 3,
            'backoff' => [30, 120, 600],  // 30s → 2 min → 10 min
            // Why longer intervals: LLM rate limits (Groq 429s) need time to clear.
            // Classification results aren't user-blocking.
        ],
        'drafts' => [
            'connection' => 'redis',
            'queue' => 'drafts',
            'processes' => 2,
            'tries' => 3,
            'backoff' => [30, 120, 600],  // same as classification
        ],
    ],
],
```

---

## LLM Pipeline

### Classification

```
Input:   Email subject + body (truncated to 4,096 tokens if needed - roughly 3,000 words)
Model:   Llama 3.1 8B (via Groq, Ollama, or stub)
Output:  JSON { "classification": "interested", "confidence": 0.92, "reasoning": "Sender asked about pricing" }
```

**Why truncate at 4,096 tokens?** A typical business email is 200–500 tokens. Truncating at 4K covers 99%+ of emails while keeping inference fast and costs predictable. The truncation preserves the subject line and the most recent message (bottom of the email body), discarding older quoted replies which add noise without improving classification accuracy.

**System prompt enforces:**
- Exactly one of: `interested`, `not_interested`, `meeting_request`, `unclear`
- Confidence score between 0.0 and 1.0
- One-sentence reasoning (stored in PostgreSQL for audit trail and displayed on the dashboard)

**Classification definitions:**
- `interested` - Sender expresses interest in a product, service, or collaboration. Examples: "I'd like to learn more about your pricing", "Can you send me a proposal?"
- `not_interested` - Sender declines, unsubscribes, or sends irrelevant content. Examples: newsletters, automated receipts, "Please remove me from your list."
- `meeting_request` - Sender explicitly requests a meeting, call, or demo. Examples: "Are you free for a call Thursday?", "I'd like to schedule a demo."
- `unclear` - Insufficient context to classify with confidence. Examples: one-word replies ("Thanks"), forwarded threads with no clear intent. Generates a draft asking a clarifying question.

### Draft Generation

```
Input:   Original email + classification label + user's display name + company context (if configured)
Model:   Llama 3.1 8B (via Groq, Ollama, or stub)
Output:  Plain text reply body (typically 50–150 words)
```

**Only triggered for:** `interested`, `meeting_request`, `unclear`. Not generated for `not_interested` - no point drafting a reply to a newsletter or spam.

**Draft tone:** The system prompt instructs the LLM to match the formality level of the incoming email. A casual email ("Hey, quick question...") gets a casual reply. A formal email ("Dear Mr. Smith, I am writing to inquire...") gets a formal reply. The user can always edit before sending.

### Stub Fallback

When `LLM_PROVIDER=stub` in `.env` (or when neither Groq nor Ollama is reachable), the `StubLlmService` returns deterministic mock responses:

- Classification: always returns `interested` with confidence `0.85` and reasoning `"Stub classification - no LLM configured."`
- Draft: returns a template reply based on the classification type (e.g. "Thank you for your interest. I'd be happy to discuss further...")

This lets the full pipeline run end-to-end during code review without any LLM running.

---

## Idempotency, Retries & Failure Handling

### Idempotency

| Layer | Strategy | How It Works |
|---|---|---|
| Pub/Sub webhook | `gmail_thread_id` unique constraint | PostgreSQL `ON CONFLICT DO NOTHING` silently skips duplicate notifications |
| Email ingestion | `gmail_message_id` unique constraint | Same - duplicate messages from retried fetches are skipped |
| Classification | Check `classification != 'pending'` | Job queries the thread before calling the LLM. If already classified, the job exits early without making an LLM call |
| Draft generation | Check if draft exists for thread | Job queries `drafts` table before calling the LLM. If a draft already exists, the job exits early |

### Retry Strategy

All queue jobs use exponential backoff with 3 attempts:

- **Ingest jobs:** 10s → 60s → 300s (5 min max). Aggressive first retry because Gmail API errors are often transient (network blip, token refresh race condition). 5-minute max because a stale `historyId` becomes invalid after ~1 hour.
- **LLM jobs:** 30s → 120s → 600s (10 min max). Longer intervals because Groq rate limits (free tier: 30 req/min) need time to clear, and classification results aren't user-blocking.

| Failure Type | HTTP Code | Handling |
|---|---|---|
| Gmail API rate limit | 429 | Retry with backoff. `RateLimiter` prevents future bursts for this account. |
| Gmail token expired | 401 | Refresh token automatically via Google's token endpoint. If refresh returns `400 invalid_grant`, mark account as `is_active = false`. |
| Gmail permissions revoked | 403 | Mark account as `is_active = false`. Surface "Reconnect your Gmail" alert on dashboard. |
| Groq rate limit | 429 | Retry with backoff (30s → 120s → 600s). Free tier resets every 60 seconds. |
| Groq server error | 500/503 | Retry with backoff. If all 3 attempts fail, job moves to `failed_jobs`. |
| Ollama not running | Connection refused | Retry with backoff. Log error for monitoring. |
| LLM returns invalid JSON | N/A | Retry once. If still invalid on second attempt, classify as `unclear` with confidence `0.0` and reasoning `"LLM returned invalid response."` |
| PostgreSQL connection lost | N/A | Laravel's built-in reconnection handles this. Queue job retries automatically. |
| Redis connection lost | N/A | Horizon detects the disconnect and restarts workers. In-flight jobs are retried from the beginning (jobs are idempotent so this is safe). |

### Dead Letter Queue

After 3 failed attempts, jobs move to Laravel's `failed_jobs` table with the full exception trace, job payload, and the queue it came from. A daily scheduled command (`notify:failed-jobs`) checks this table and alerts via Slack or email if any jobs are stuck. Failed jobs can be retried individually with `php artisan queue:retry {id}` or in bulk with `php artisan queue:retry all`.

---

## Scaling to Many Accounts

The architecture is designed for many connected accounts from day one:

**Gmail sync scales horizontally.** Each Pub/Sub notification is independent - no shared state between account syncs. Adding more Horizon workers to the `gmail-ingest` queue linearly increases throughput. 3 workers processing at ~0.5s per job = ~6 emails/second = ~518,400 emails/day.

**Queue isolation prevents noisy neighbors.** One account receiving 1,000 emails in a burst doesn't starve other accounts because:
- Jobs are dispatched individually per email, not batched per account.
- Horizon's auto-balancing distributes jobs across workers using a round-robin strategy.
- Each queue has its own worker pool - a slow LLM queue doesn't block email ingestion.

**Database partitioning path (future).** The partitioning trigger is when query performance degrades - specifically when the `email_threads` table exceeds ~10M rows and index scans on the composite `(gmail_account_id, created_at)` index start taking >50ms (measurable via PostgreSQL's `EXPLAIN ANALYZE`). At that point, partition by `gmail_account_id` using PostgreSQL's native `PARTITION BY HASH`. Since all dashboard queries already filter by `gmail_account_id` (it's the leading column in every index), PostgreSQL automatically prunes to the correct partition.

**Token refresh is per-account.** No global token or shared credential. Each account's OAuth tokens are encrypted independently with Laravel's `APP_KEY`. Revoking one account's access has zero impact on other accounts.

**Connection limits.** Each Horizon worker holds one persistent Redis connection and one PostgreSQL connection. With 7 workers (3 + 2 + 2) plus the main `app` process, that's **8 PostgreSQL connections + 8 Redis connections**. PostgreSQL's default `max_connections` is 100. Redis's default `maxclients` is 10,000. At 8 connections each, we're using 8% of PostgreSQL's capacity and <1% of Redis's. At scale (50+ workers), add PgBouncer as a PostgreSQL connection pooler to multiplex hundreds of worker connections through a smaller pool of persistent database connections.

---

## Observability

### Logging

- **Format:** Structured JSON logs via Laravel's Monolog with `LOG_CHANNEL=stack` (daily rotating file + stderr for Docker).
- **Job logs:** Every queue job logs `account_id`, `thread_id`, `job_type`, `duration_ms`, and `status` (success / failed / retried).
- **LLM logs:** Every LLM call logs `provider` (groq/ollama/stub), `model`, `input_tokens`, `output_tokens`, and `latency_ms`.

### Metrics (Horizon Dashboard)

Horizon provides a built-in web dashboard at `/horizon` showing:

- Jobs processed per minute (broken down by queue).
- Job wait time (how long jobs sit in the queue before a worker picks them up).
- Job runtime (how long each job takes to execute).
- Failed job count with full exception traces.
- Worker utilization per queue.

### Health Checks

- `GET /api/health` - returns HTTP 200 with `{"status": "ok", "postgres": "connected", "redis": "connected", "horizon": "running"}` if all services are reachable. Returns HTTP 503 if any dependency is down.
- `GET /api/health/gmail` - returns per-account sync status: `gmail_email`, `is_active`, `last_sync_at`, `watch_expires_at`.

### Alerting (production)

- Failed job count > 0 → Slack notification via Laravel's notification system.
- Queue wait time > 60 seconds → Slack notification (indicates workers are overwhelmed).
- Account token refresh failure → email to admin + "Reconnect" badge on dashboard.

---

## Deploy Story

### Local Development

See [Getting Started](#getting-started) for full step-by-step instructions.

### Docker Compose Services

| Service | Image | Port | Purpose |
|---|---|---|---|
| `app` | Laravel + PHP 8.4 | 8000 | REST API server |
| `frontend` | Next.js 15 | 3000 | Dashboard UI |
| `postgres` | PostgreSQL 15 Alpine | 5432 | Persistent storage |
| `redis` | Redis 7 Alpine | 6379 | Queue broker + cache |
| `horizon` | Same image as `app` | - | Background queue workers (3 ingest + 2 classification + 2 draft) |
| `ollama` | Ollama (optional) | 11434 | Local LLM inference (only needed if `LLM_PROVIDER=ollama`) |

### Kubernetes (Bonus)

The `k8s/` directory contains production-ready manifests:

```
k8s/
├── namespace.yaml
├── configmap.yaml
├── secrets.yaml              # references to external secret store (never hardcoded)
├── deployments/
│   ├── app.yaml              # Laravel API (3 replicas, HPA scales on CPU > 70%)
│   ├── horizon.yaml          # Queue workers (separate deployment, scales on queue depth)
│   ├── frontend.yaml         # Next.js (2 replicas)
│   └── scheduler.yaml        # Laravel scheduler (CronJob, not inside API pod)
├── services/
│   ├── app-service.yaml      # ClusterIP → Ingress
│   └── frontend-service.yaml # ClusterIP → Ingress
├── ingress.yaml              # TLS termination via cert-manager
├── hpa/
│   ├── app-hpa.yaml          # Scale API pods on CPU > 70%
│   └── horizon-hpa.yaml      # Scale workers on custom queue-depth metric
└── jobs/
    └── migrate.yaml          # One-shot migration job, runs before deployment completes
```

**Key K8s decisions:**

- **Horizon workers are a separate Deployment** from the API so they scale independently. The API scales on CPU utilization. Horizon scales on queue depth (custom metric exposed via Prometheus adapter). A burst of 10,000 incoming emails scales up workers without scaling the API.
- **The Laravel scheduler runs as a CronJob**, not inside the API pod. This guarantees single-execution - if the scheduler ran inside a replicated API Deployment, the `gmail:renew-watches` command would execute N times (once per replica).
- **Secrets reference an external store** (AWS Secrets Manager or GCP Secret Manager) via the External Secrets Operator. The `APP_KEY`, `GROQ_API_KEY`, Google OAuth credentials, and database password are never hardcoded in manifests or ConfigMaps.
- **HPA for Horizon** uses a custom metric: Redis queue length exposed via a Prometheus exporter. When `gmail-ingest` queue depth exceeds 100 pending jobs, HPA adds workers. When it drops below 20, it scales down.

---

## What I'd Build Next

If this were a real product and I had more time:

1. **Thread-aware classification** - Pass the full thread history (all messages, not just the latest) to the LLM. Reclassify when new replies arrive in an existing thread.
2. **User-defined classification rules** - Let users add custom categories (e.g. "support ticket", "partnership inquiry") and provide few-shot examples that are included in the system prompt.
3. **Batch classification** - Group multiple emails into a single LLM call to reduce API overhead and latency. Groq's free tier allows 30 requests/min - batching 5 emails per call would effectively support 150 emails/min.
4. **Analytics dashboard** - Classification distribution over time, response rate, average time-to-reply, most active senders.
5. **Multi-user teams** - Shared inbox support where multiple team members see the same threads with role-based access (viewer, editor, admin).
6. **Webhook for external integrations** - Fire a webhook when an email is classified, so CRMs (HubSpot, Salesforce) can ingest the classification data.
7. **Email template library** - Pre-built reply templates by classification type that users can customize and set as defaults.
8. **Upgrade to Llama 3.1 70B** - Use Groq's paid tier for the 70B model when classification accuracy matters more than cost. The adapter pattern makes this a one-line config change.

---

## What I Deliberately Skipped

| Skipped | Why |
|---|---|
| Full Gmail sync (backfill) | The assignment focuses on new incoming emails, not migrating entire inbox history. The architecture supports backfill (call `history.list` with an older `historyId`) but building it doesn't demonstrate additional architectural thinking. |
| Email compose UI (new threads) | Out of scope - the system generates and sends replies to existing threads, not new conversations. |
| Multi-tenant SaaS auth (RBAC, billing) | The current auth model supports multiple users natively (each user registers and connects their own Gmail). True multi-tenancy with org-level accounts and billing is a product concern, not an architecture demo. |
| WebSocket real-time updates | The dashboard uses polling (5-second interval via `setInterval` + `fetch`) for simplicity. The production upgrade would be Laravel Reverb (WebSocket server) broadcasting classification-complete events for instant UI updates. |
| End-to-end encryption of email bodies | Tokens are encrypted at rest via Laravel's `encrypted` cast. Full E2E encryption of email content would require a client-side key management system - overkill for a take-home. |
| SOC 2 compliance features | Audit logging, data retention policies, and role-based access controls are organizational processes, not code artifacts. The encrypted token storage and structured JSON logging are the technical foundation that a SOC 2 audit would build on. |

---

## Getting Started

### Prerequisites

Make sure you have these installed before starting:

- **Docker Desktop** (or Docker Engine + Compose plugin) - [install guide](https://docs.docker.com/get-docker/)
- **Git** - [install guide](https://git-scm.com/downloads)

Verify everything is ready:

```bash
docker --version        # Docker 24+
docker compose version  # Compose v2+
git --version           # any version
```

You do **not** need PHP, Composer, Node.js, PostgreSQL, or Redis installed locally - Docker handles all of that.

### Step 1 - Clone the repository

```bash
git clone https://github.com/<username>/gmail-autoresponder.git
cd gmail-autoresponder
```

### Step 2 - Configure environment variables

```bash
cp .env.example .env
```

Open `.env` in your editor and set these values:

**Database credentials (required):**

- **DB_PASSWORD** - Choose any password for the PostgreSQL database user (e.g. `mydevpass123`)

This is for your local Docker database only - it never leaves your machine. PostgreSQL reads this on **first launch only**. If you need to change it later, run `docker compose down -v` first to reset the database.

**LLM provider (required - choose one):**

- **LLM_PROVIDER** - Set to `groq`, `ollama`, or `stub`

| Provider | What to set | What you need |
|---|---|---|
| `groq` | `LLM_PROVIDER=groq` and `GROQ_API_KEY=your-key` | Free API key from [console.groq.com](https://console.groq.com) (takes 30 seconds) |
| `ollama` | `LLM_PROVIDER=ollama` | Nothing - Ollama runs in Docker automatically. Requires ~8GB RAM. |
| `stub` | `LLM_PROVIDER=stub` | Nothing - returns mock responses. Use this for code review without any LLM. |

**Google OAuth credentials (required for Gmail features):**

- **GOOGLE_CLIENT_ID** - From Google Cloud Console (see below)
- **GOOGLE_CLIENT_SECRET** - From Google Cloud Console (see below)
- **GOOGLE_REDIRECT_URI** - Leave as `http://localhost:8000/auth/google/callback`

**How to get Google OAuth credentials:**

1. Go to [Google Cloud Console](https://console.cloud.google.com/)
2. Create a new project (or select an existing one)
3. Navigate to **APIs & Services → Library**
4. Search for **Gmail API** → click → click **Enable**
5. Navigate to **APIs & Services → Credentials**
6. Click **Create Credentials → OAuth Client ID**
7. Application type: select **Web application**
8. Under "Authorized redirect URIs", click **Add URI** and enter: `http://localhost:8000/auth/google/callback`
9. Click **Create**
10. Copy the **Client ID** and **Client Secret** into your `.env` file

**Google Pub/Sub (optional - for real-time push notifications):**

- **GOOGLE_CLOUD_PROJECT_ID** - Your Google Cloud project ID (visible in the project selector dropdown)
- **GOOGLE_PUBSUB_TOPIC** - Leave as `gmail-notifications`
- **GOOGLE_PUBSUB_SUBSCRIPTION** - Leave as `gmail-push`
- If you skip Pub/Sub setup, the app falls back to polling mode (checks Gmail every 60 seconds via a scheduled command).

### Step 3 - Start the application

```bash
docker compose up -d
```

This starts all services: Laravel API, Next.js frontend, PostgreSQL, Redis, Horizon queue workers, and Ollama (if `LLM_PROVIDER=ollama`). First launch takes ~60–90 seconds while containers build and PostgreSQL initializes.

### Step 4 - Run database migrations

```bash
docker compose exec app php artisan migrate
docker compose exec app php artisan db:seed
```

This creates all database tables and seeds a demo user account.

### Step 5 - Open the application

- **Dashboard:** http://localhost:3000 - The main UI where you connect Gmail, view threads, and manage drafts
- **API:** http://localhost:8000 - The Laravel backend REST API
- **Horizon:** http://localhost:8000/horizon - Queue monitoring dashboard showing job throughput, failures, and worker status

### What you should see

1. Open http://localhost:3000 - you'll see a login page
2. Register or log in with the seeded demo account
3. Click "Connect Gmail" - redirects to Google OAuth consent screen
4. After granting access, incoming emails will appear in the dashboard with classifications and AI-generated draft replies
5. With `LLM_PROVIDER=stub`, classifications and drafts use realistic mock data - the full pipeline runs without any LLM

### Useful commands

```bash
docker compose up -d              # Start all services
docker compose down               # Stop all services (database preserved)
docker compose down -v            # Stop AND delete all data (fresh start)
docker compose logs -f app        # Stream Laravel logs
docker compose logs -f horizon    # Stream queue worker logs
docker compose exec app bash      # Shell into the Laravel container
docker compose exec app php artisan horizon:status  # Check Horizon status
```

### Troubleshooting

**"Error establishing a database connection"**
Open `.env` and make sure `DB_PASSWORD` is set to a real value, not the placeholder. If you changed the password after Docker already started once, run `docker compose down -v` to reset the database, then `docker compose up -d`.

**Google OAuth redirect fails**
Make sure `GOOGLE_REDIRECT_URI` in `.env` matches exactly what you entered in Google Cloud Console: `http://localhost:8000/auth/google/callback`. The URL must match character-for-character, including the protocol (`http`, not `https` for localhost).

**Containers won't start**
Check if ports 8000, 3000, 5432, or 6379 are already in use: `lsof -i :8000`. Stop the conflicting process or change the port in `docker-compose.yml`.

**Queue jobs not processing**
Check if Horizon is running: `docker compose logs horizon`. If it crashed, restart with `docker compose restart horizon`. Check the Horizon dashboard at http://localhost:8000/horizon for failed job details.

**Ollama is slow**
Ollama runs on CPU by default, which takes 5–15 seconds per LLM call. If this is too slow, switch to Groq: set `LLM_PROVIDER=groq` and `GROQ_API_KEY=your-key` in `.env`, then restart: `docker compose restart app horizon`.

---

## Tech Stack

| Layer | Technology | Version |
|---|---|---|
| Backend | Laravel | 12.x |
| Frontend | Next.js (App Router, TypeScript) | 15.x |
| Database | PostgreSQL | 15.x |
| Cache / Queue | Redis | 7.x |
| LLM (default) | Groq - Llama 3.1 8B | Free tier |
| LLM (alternative) | Ollama - Llama 3.1 8B | Local, free |
| LLM (fallback) | Stub - deterministic mock responses | Built-in |
| Queue Dashboard | Laravel Horizon | 5.x |
| Gmail | Google Gmail API + Pub/Sub | v1 |
| Auth | Laravel Socialite + Google OAuth 2.0 | - |
| Containers | Docker + Docker Compose | - |
| Orchestration | Kubernetes (bonus) | - |
