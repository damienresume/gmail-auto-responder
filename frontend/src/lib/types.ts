/**
 * TypeScript Types
 *
 * PURPOSE:
 * Defines the shape of data returned by the Laravel API. These types
 * mirror the Eloquent model JSON output so the frontend has compile-time
 * guarantees about what fields exist on each object.
 *
 * WHY separate from API client:
 * Types are used by components, pages, and utilities across the app.
 * Keeping them in their own file avoids circular imports and makes it
 * easy to find all data shapes in one place.
 */

/** Matches the EmailThread model's JSON output with eager-loaded relations. */
export interface Thread {
  id: number;
  gmail_account_id: number;
  gmail_thread_id: string;
  subject: string;
  from_email: string;
  from_name: string | null;
  classification: 'interested' | 'not_interested' | 'meeting_request' | 'unclear' | null;
  confidence_score: string | null;
  classification_reasoning: string | null;
  classified_at: string | null;
  metadata: Record<string, unknown> | null;
  created_at: string;
  updated_at: string;
  latest_draft: Draft | null;
  gmail_account: { id: number; gmail_email: string } | null;
  messages?: Message[];
}

/** Matches the EmailMessage model's JSON output. */
export interface Message {
  id: number;
  email_thread_id: number;
  gmail_message_id: string;
  direction: 'inbound' | 'outbound';
  body_text: string | null;
  body_html: string | null;
  received_at: string | null;
  created_at: string;
}

/** Matches the Draft model's JSON output with eager-loaded thread. */
export interface Draft {
  id: number;
  email_thread_id: number;
  gmail_draft_id: string | null;
  body_text: string;
  body_html: string | null;
  status: 'generated' | 'approved' | 'sent' | 'discarded';
  revision: number;
  sent_at: string | null;
  created_at: string;
  updated_at: string;
  email_thread?: {
    id: number;
    subject: string;
    from_email: string;
    from_name: string | null;
    classification: string | null;
  };
}

/** Laravel cursor pagination wrapper. */
export interface PaginatedResponse<T> {
  data: T[];
  next_cursor: string | null;
  next_page_url: string | null;
  prev_cursor: string | null;
  prev_page_url: string | null;
  per_page: number;
}
