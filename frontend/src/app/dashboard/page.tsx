/**
 * Threads List Page (/dashboard)
 *
 * PURPOSE:
 * The main dashboard view. Shows all email threads with their classification
 * status, sender info, and draft state. Users can filter by classification
 * and click into a thread for detail view.
 *
 * WHY a client component ('use client'):
 * This page fetches data from the API on the client side because:
 *   - The API requires authentication cookies, which are only available
 *     in the browser (not in Next.js server-side rendering).
 *   - The page needs interactive state (filter tabs, loading indicators).
 *   - Client-side fetching enables real-time updates without page reloads.
 */
'use client';

import { useEffect, useState, useCallback } from 'react';
import Link from 'next/link';
import { api } from '@/lib/api';
import type { Thread, PaginatedResponse } from '@/lib/types';

/**
 * Classification badge colors.
 *
 * PURPOSE:
 * Maps each classification to a Tailwind color class so the UI gives
 * instant visual feedback about email priority. Green for interested
 * (action needed), red for not interested (no action), etc.
 */
const classificationStyles: Record<string, { bg: string; text: string; label: string }> = {
  interested: { bg: 'bg-green-100', text: 'text-green-800', label: 'Interested' },
  not_interested: { bg: 'bg-gray-100', text: 'text-gray-800', label: 'Not Interested' },
  meeting_request: { bg: 'bg-blue-100', text: 'text-blue-800', label: 'Meeting Request' },
  unclear: { bg: 'bg-yellow-100', text: 'text-yellow-800', label: 'Unclear' },
};

export default function ThreadsPage() {
  const [threads, setThreads] = useState<Thread[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [filter, setFilter] = useState<string | null>(null);

  // Fetch threads from the API. useCallback so the polling interval
  // can call the same function without re-creating it.
  const fetchThreads = useCallback(async (showLoading = true) => {
    if (showLoading) setLoading(true);
    const endpoint = filter
      ? `/api/threads?classification=${filter}`
      : '/api/threads';
    const response = await api.get<PaginatedResponse<Thread>>(endpoint);

    if (response.error) {
      setError(response.error);
    } else {
      setThreads(response.data?.data ?? []);
      setError(null);
    }
    if (showLoading) setLoading(false);
  }, [filter]);

  // Initial fetch on mount and when filter changes.
  useEffect(() => {
    fetchThreads(true);
  }, [fetchThreads]);

  // Auto-refresh every 10 seconds so new emails appear automatically.
  // This is especially important when the user first connects Gmail
  // and the database is empty — the scheduler fetches emails in the
  // background and the dashboard picks them up on the next poll.
  // showLoading=false so the refresh is silent (no flicker).
  useEffect(() => {
    const interval = setInterval(() => fetchThreads(false), 10000);
    return () => clearInterval(interval);
  }, [fetchThreads]);

  // Filter tab buttons. null = show all threads.
  const filters = [
    { value: null, label: 'All' },
    { value: 'interested', label: 'Interested' },
    { value: 'meeting_request', label: 'Meeting Requests' },
    { value: 'unclear', label: 'Unclear' },
    { value: 'not_interested', label: 'Not Interested' },
  ];

  return (
    <div>
      <h2 className="text-2xl font-bold text-gray-900 mb-6">Email Threads</h2>

      {/* Classification filter tabs */}
      <div className="flex gap-2 mb-6">
        {filters.map((f) => (
          <button
            key={f.value ?? 'all'}
            onClick={() => setFilter(f.value)}
            className={`px-4 py-2 rounded-md text-sm font-medium transition-colors ${
              filter === f.value
                ? 'bg-blue-600 text-white'
                : 'bg-white text-gray-700 border border-gray-300 hover:bg-gray-50'
            }`}
          >
            {f.label}
          </button>
        ))}
      </div>

      {/* Loading state */}
      {loading && (
        <div className="text-center py-12 text-gray-500">Loading threads...</div>
      )}

      {/* Error state */}
      {error && (
        <div className="bg-red-50 border border-red-200 rounded-md p-4 text-red-800">
          {error}
        </div>
      )}

      {/* Empty state */}
      {!loading && !error && threads.length === 0 && (
        <div className="text-center py-12">
          <p className="text-gray-500 mb-4">No threads found.</p>
          <p className="text-sm text-gray-400">
            Connect a Gmail account to start receiving emails.
          </p>
        </div>
      )}

      {/* Thread list */}
      {!loading && threads.length > 0 && (
        <div className="bg-white rounded-lg border border-gray-200 divide-y divide-gray-200">
          {threads.map((thread) => {
            const style = thread.classification
              ? classificationStyles[thread.classification]
              : null;

            return (
              <Link
                key={thread.id}
                href={`/dashboard/threads/${thread.id}`}
                className="block p-4 hover:bg-gray-50 transition-colors"
              >
                <div className="flex items-start justify-between gap-4">
                  <div className="min-w-0 flex-1">
                    <div className="flex items-center gap-2 mb-1">
                      <span className="font-medium text-gray-900 truncate">
                        {thread.from_name || thread.from_email}
                      </span>
                      {thread.gmail_account && (
                        <span className="text-xs text-gray-400">
                          via {thread.gmail_account.gmail_email}
                        </span>
                      )}
                    </div>
                    <p className="text-sm text-gray-900 truncate">{thread.subject}</p>
                    <p className="text-xs text-gray-500 mt-1">
                      {new Date((thread as unknown as Record<string, string>).last_message_at || thread.updated_at).toLocaleDateString(undefined, {
                        month: 'short', day: 'numeric', year: 'numeric',
                        hour: '2-digit', minute: '2-digit',
                      })}
                    </p>
                  </div>

                  <div className="flex items-center gap-2 flex-shrink-0">
                    {/* Classification badge - always shows one of the four categories */}
                    {style && (
                      <span className={`px-2 py-1 rounded-full text-xs font-medium ${style.bg} ${style.text}`}>
                        {style.label}
                      </span>
                    )}

                    {/* Draft status indicator */}
                    {thread.latest_draft && (
                      <span className={`px-2 py-1 rounded-full text-xs font-medium ${
                        thread.latest_draft.status === 'generated'
                          ? 'bg-orange-100 text-orange-800'
                          : thread.latest_draft.status === 'sent'
                          ? 'bg-green-100 text-green-800'
                          : 'bg-gray-100 text-gray-600'
                      }`}>
                        {thread.latest_draft.status === 'generated' ? 'Draft Ready' :
                         thread.latest_draft.status === 'approved' ? 'Approved' :
                         thread.latest_draft.status === 'sent' ? 'Sent' : 'Discarded'}
                      </span>
                    )}
                  </div>
                </div>
              </Link>
            );
          })}
        </div>
      )}
    </div>
  );
}
