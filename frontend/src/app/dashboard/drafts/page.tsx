/**
 * Drafts Review Page (/dashboard/drafts)
 *
 * PURPOSE:
 * Shows all drafts pending human review in a dedicated queue view.
 * Users can quickly scan draft replies and approve, discard, or click
 * into the thread detail for editing. This is the action-oriented view
 * where users process their review queue.
 *
 * WHY a separate page from the threads list:
 * The threads list shows ALL threads (classified or not, with or without
 * drafts). The drafts page focuses exclusively on actionable items that
 * need human review. This matches how sales teams work: they process
 * their "inbox" of pending replies in batch, not by searching through
 * all threads.
 */
'use client';

import { useEffect, useState } from 'react';
import Link from 'next/link';
import { api } from '@/lib/api';
import type { Draft, PaginatedResponse } from '@/lib/types';

export default function DraftsPage() {
  const [drafts, setDrafts] = useState<Draft[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [statusFilter, setStatusFilter] = useState('generated');
  const [actionLoading, setActionLoading] = useState<number | null>(null);

  useEffect(() => {
    fetchDrafts();
  }, [statusFilter]);

  async function fetchDrafts() {
    setLoading(true);
    const response = await api.get<PaginatedResponse<Draft>>(
      `/api/drafts?status=${statusFilter}`
    );
    if (response.error) {
      setError(response.error);
    } else {
      setDrafts(response.data?.data ?? []);
    }
    setLoading(false);
  }

  async function handleAction(draftId: number, action: string) {
    setActionLoading(draftId);
    const response = await api.post<Draft>(`/api/drafts/${draftId}/${action}`);
    if (response.error) {
      setError(response.error);
    } else {
      // Remove the draft from the list since its status changed.
      // This gives instant visual feedback without a full refetch.
      setDrafts((prev) => prev.filter((d) => d.id !== draftId));
    }
    setActionLoading(null);
  }

  const statusTabs = [
    { value: 'generated', label: 'Needs Review' },
    { value: 'approved', label: 'Approved' },
    { value: 'sent', label: 'Sent' },
    { value: 'discarded', label: 'Discarded' },
  ];

  return (
    <div>
      <h2 className="text-2xl font-bold text-gray-900 mb-6">Draft Review Queue</h2>

      {/* Status filter tabs */}
      <div className="flex gap-2 mb-6">
        {statusTabs.map((tab) => (
          <button
            key={tab.value}
            onClick={() => setStatusFilter(tab.value)}
            className={`px-4 py-2 rounded-md text-sm font-medium transition-colors ${
              statusFilter === tab.value
                ? 'bg-blue-600 text-white'
                : 'bg-white text-gray-700 border border-gray-300 hover:bg-gray-50'
            }`}
          >
            {tab.label}
          </button>
        ))}
      </div>

      {loading && (
        <div className="text-center py-12 text-gray-500">Loading drafts...</div>
      )}

      {error && (
        <div className="bg-red-50 border border-red-200 rounded-md p-4 text-red-800 mb-4">
          {error}
        </div>
      )}

      {!loading && !error && drafts.length === 0 && (
        <div className="text-center py-12">
          <p className="text-gray-500">
            {statusFilter === 'generated'
              ? 'No drafts pending review. All caught up!'
              : `No ${statusFilter} drafts.`}
          </p>
        </div>
      )}

      {!loading && drafts.length > 0 && (
        <div className="space-y-4">
          {drafts.map((draft) => (
            <div
              key={draft.id}
              className="bg-white border border-gray-200 rounded-lg p-4"
            >
              <div className="flex items-start justify-between gap-4">
                <div className="min-w-0 flex-1">
                  {/* Thread context */}
                  {draft.email_thread && (
                    <div className="mb-2">
                      <Link
                        href={`/dashboard/threads/${draft.email_thread.id}`}
                        className="text-sm font-medium text-blue-600 hover:text-blue-800"
                      >
                        {draft.email_thread.subject}
                      </Link>
                      <p className="text-xs text-gray-500">
                        From: {draft.email_thread.from_name || draft.email_thread.from_email}
                        {draft.email_thread.classification && (
                          <span className="ml-2 text-gray-400">
                            ({draft.email_thread.classification})
                          </span>
                        )}
                      </p>
                    </div>
                  )}

                  {/* Draft preview (first 200 characters) */}
                  <p className="text-sm text-gray-700 line-clamp-3">
                    {draft.body_text}
                  </p>

                  <p className="text-xs text-gray-400 mt-2">
                    Revision {draft.revision} | Created {new Date(draft.created_at).toLocaleString()}
                  </p>
                </div>

                {/* Quick action buttons */}
                <div className="flex gap-2 flex-shrink-0">
                  {draft.status === 'generated' && (
                    <>
                      <button
                        onClick={() => handleAction(draft.id, 'approve')}
                        disabled={actionLoading === draft.id}
                        className="px-3 py-1.5 text-xs font-medium text-white bg-green-600 rounded-md hover:bg-green-700 disabled:opacity-50"
                      >
                        Approve
                      </button>
                      <button
                        onClick={() => handleAction(draft.id, 'discard')}
                        disabled={actionLoading === draft.id}
                        className="px-3 py-1.5 text-xs font-medium text-red-700 bg-white border border-red-300 rounded-md hover:bg-red-50 disabled:opacity-50"
                      >
                        Discard
                      </button>
                    </>
                  )}
                  {draft.status === 'approved' && (
                    <button
                      onClick={() => handleAction(draft.id, 'send')}
                      disabled={actionLoading === draft.id}
                      className="px-3 py-1.5 text-xs font-medium text-white bg-green-600 rounded-md hover:bg-green-700 disabled:opacity-50"
                    >
                      Send
                    </button>
                  )}
                  {draft.status === 'sent' && draft.sent_at && (
                    <span className="text-xs text-green-600">
                      Sent {new Date(draft.sent_at).toLocaleDateString()}
                    </span>
                  )}
                </div>
              </div>
            </div>
          ))}
        </div>
      )}
    </div>
  );
}
