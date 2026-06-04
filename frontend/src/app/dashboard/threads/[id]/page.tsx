/**
 * Thread Detail Page (/dashboard/threads/[id])
 *
 * PURPOSE:
 * Shows a single email thread's full conversation history alongside the
 * LLM-generated draft reply. Users can review, edit, approve, send, or
 * discard the draft from this page. This is the human-in-the-loop control
 * point where users decide what actually gets sent.
 *
 * WHY a dynamic route ([id]):
 * Each thread has a unique ID. The [id] segment in the file path tells
 * Next.js to create a dynamic route that captures the thread ID from
 * the URL (e.g., /dashboard/threads/42 → params.id = '42').
 */
'use client';

import { useEffect, useState } from 'react';
import { useParams, useRouter } from 'next/navigation';
import { api } from '@/lib/api';
import type { Thread, Draft } from '@/lib/types';

export default function ThreadDetailPage() {
  const params = useParams();
  const router = useRouter();
  const threadId = params.id;

  const [thread, setThread] = useState<Thread | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [actionLoading, setActionLoading] = useState<string | null>(null);
  const [editMode, setEditMode] = useState(false);
  const [editText, setEditText] = useState('');

  useEffect(() => {
    async function fetchThread() {
      setLoading(true);
      const response = await api.get<Thread>(`/api/threads/${threadId}`);
      if (response.error) {
        setError(response.error);
      } else if (response.data) {
        setThread(response.data);
        if (response.data.latest_draft) {
          setEditText(response.data.latest_draft.body_text);
        }
      }
      setLoading(false);
    }
    fetchThread();
  }, [threadId]);

  /**
   * Perform a draft action (approve, send, discard, or save edit).
   *
   * PURPOSE:
   * All draft lifecycle actions go through this function. It handles
   * loading state, error display, and refreshing the thread data after
   * the action completes. This prevents duplicating fetch/error logic
   * across each action button handler.
   */
  async function handleDraftAction(action: string, body?: unknown) {
    if (!thread?.latest_draft) return;
    const draftId = thread.latest_draft.id;
    setActionLoading(action);

    let response;
    if (action === 'edit') {
      response = await api.put<Draft>(`/api/drafts/${draftId}`, body);
    } else {
      response = await api.post<Draft>(`/api/drafts/${draftId}/${action}`);
    }

    if (response.error) {
      setError(response.error);
    } else {
      // Refresh the thread to get updated draft state.
      const refreshed = await api.get<Thread>(`/api/threads/${threadId}`);
      if (refreshed.data) {
        setThread(refreshed.data);
        if (refreshed.data.latest_draft) {
          setEditText(refreshed.data.latest_draft.body_text);
        }
        setEditMode(false);
      }
    }
    setActionLoading(null);
  }

  if (loading) {
    return <div className="text-center py-12 text-gray-500">Loading thread...</div>;
  }

  if (error || !thread) {
    return (
      <div className="bg-red-50 border border-red-200 rounded-md p-4 text-red-800">
        {error || 'Thread not found'}
      </div>
    );
  }

  const draft = thread.latest_draft;

  return (
    <div>
      {/* Back button and header */}
      <button
        onClick={() => router.push('/dashboard')}
        className="text-sm text-blue-600 hover:text-blue-800 mb-4 inline-block"
      >
        ← Back to threads
      </button>

      <div className="mb-6">
        <h2 className="text-2xl font-bold text-gray-900">{thread.subject}</h2>
        <p className="text-sm text-gray-500 mt-1">
          From: {thread.from_name ? `${thread.from_name} <${thread.from_email}>` : thread.from_email}
        </p>
        {thread.classification && (
          <div className="mt-2">
            <span className="text-sm text-gray-600">
              Classification: <strong>{thread.classification}</strong>
              {thread.confidence_score && ` (${(parseFloat(thread.confidence_score) * 100).toFixed(0)}% confidence)`}
            </span>
            {thread.classification_reasoning && (
              <p className="text-sm text-gray-500 mt-1 italic">
                {thread.classification_reasoning}
              </p>
            )}
          </div>
        )}
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
        {/* Conversation messages */}
        <div>
          <h3 className="text-lg font-semibold text-gray-900 mb-4">Conversation</h3>
          <div className="space-y-4">
            {thread.messages && thread.messages.length > 0 ? (
              thread.messages.map((msg) => (
                <div
                  key={msg.id}
                  className={`p-4 rounded-lg ${
                    msg.direction === 'inbound'
                      ? 'bg-white border border-gray-200'
                      : 'bg-blue-50 border border-blue-200 ml-8'
                  }`}
                >
                  <div className="flex justify-between items-center mb-2">
                    <span className="text-xs font-medium text-gray-500 uppercase">
                      {msg.direction === 'inbound' ? 'Received' : 'Sent'}
                    </span>
                    {msg.received_at && (
                      <span className="text-xs text-gray-400">
                        {new Date(msg.received_at).toLocaleString()}
                      </span>
                    )}
                  </div>
                  <div className="text-sm text-gray-800 whitespace-pre-wrap">
                    {msg.body_text || '(No text content)'}
                  </div>
                </div>
              ))
            ) : (
              <p className="text-gray-500 text-sm">No messages loaded.</p>
            )}
          </div>
        </div>

        {/* Draft reply panel */}
        <div>
          <h3 className="text-lg font-semibold text-gray-900 mb-4">Draft Reply</h3>
          {draft ? (
            <div className="bg-white border border-gray-200 rounded-lg p-4">
              {/* Draft status badge */}
              <div className="flex justify-between items-center mb-4">
                <span className={`px-2 py-1 rounded-full text-xs font-medium ${
                  draft.status === 'generated' ? 'bg-orange-100 text-orange-800' :
                  draft.status === 'approved' ? 'bg-blue-100 text-blue-800' :
                  draft.status === 'sent' ? 'bg-green-100 text-green-800' :
                  'bg-gray-100 text-gray-600'
                }`}>
                  {draft.status.charAt(0).toUpperCase() + draft.status.slice(1)}
                </span>
                <span className="text-xs text-gray-400">
                  Revision {draft.revision}
                </span>
              </div>

              {/* Draft body (editable or read-only) */}
              {editMode ? (
                <textarea
                  value={editText}
                  onChange={(e) => setEditText(e.target.value)}
                  className="w-full h-48 p-3 text-sm border border-gray-300 rounded-md focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                />
              ) : (
                <div className="text-sm text-gray-800 whitespace-pre-wrap bg-gray-50 p-3 rounded-md min-h-[12rem]">
                  {draft.body_text}
                </div>
              )}

              {/* Action buttons. Visible based on draft status. */}
              <div className="flex gap-2 mt-4 flex-wrap">
                {draft.status === 'generated' && (
                  <>
                    {editMode ? (
                      <>
                        <button
                          onClick={() => handleDraftAction('edit', { body_text: editText })}
                          disabled={actionLoading === 'edit'}
                          className="px-4 py-2 text-sm font-medium text-white bg-blue-600 rounded-md hover:bg-blue-700 disabled:opacity-50"
                        >
                          {actionLoading === 'edit' ? 'Saving...' : 'Save Edit'}
                        </button>
                        <button
                          onClick={() => { setEditMode(false); setEditText(draft.body_text); }}
                          className="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-50"
                        >
                          Cancel
                        </button>
                      </>
                    ) : (
                      <>
                        <button
                          onClick={() => setEditMode(true)}
                          className="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-50"
                        >
                          Edit
                        </button>
                        <button
                          onClick={() => handleDraftAction('approve')}
                          disabled={actionLoading === 'approve'}
                          className="px-4 py-2 text-sm font-medium text-white bg-green-600 rounded-md hover:bg-green-700 disabled:opacity-50"
                        >
                          {actionLoading === 'approve' ? 'Approving...' : 'Approve'}
                        </button>
                        <button
                          onClick={() => handleDraftAction('discard')}
                          disabled={actionLoading === 'discard'}
                          className="px-4 py-2 text-sm font-medium text-red-700 bg-white border border-red-300 rounded-md hover:bg-red-50 disabled:opacity-50"
                        >
                          {actionLoading === 'discard' ? 'Discarding...' : 'Discard'}
                        </button>
                      </>
                    )}
                  </>
                )}

                {draft.status === 'approved' && (
                  <button
                    onClick={() => handleDraftAction('send')}
                    disabled={actionLoading === 'send'}
                    className="px-4 py-2 text-sm font-medium text-white bg-green-600 rounded-md hover:bg-green-700 disabled:opacity-50"
                  >
                    {actionLoading === 'send' ? 'Sending...' : 'Send via Gmail'}
                  </button>
                )}

                {draft.status === 'sent' && draft.sent_at && (
                  <p className="text-sm text-green-600">
                    Sent on {new Date(draft.sent_at).toLocaleString()}
                  </p>
                )}
              </div>
            </div>
          ) : (
            <div className="bg-gray-50 border border-gray-200 rounded-lg p-4 text-center">
              <p className="text-gray-500 text-sm">
                {thread.classification === 'not_interested'
                  ? 'No draft generated (classified as not interested).'
                  : 'Draft generation in progress...'}
              </p>
            </div>
          )}
        </div>
      </div>
    </div>
  );
}
