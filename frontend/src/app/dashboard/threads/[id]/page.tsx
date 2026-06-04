/**
 * Thread Detail Page (/dashboard/threads/[id])
 *
 * PURPOSE:
 * Shows a single email thread in a Gmail-like layout. Messages are
 * collapsed by default showing only the sender and a snippet. Clicking
 * a message expands it to show the full body. The draft reply panel
 * sits below the conversation, just like Gmail's reply box.
 *
 * WHY Gmail-style collapsed messages:
 * Threads can have many messages (10+ in a long conversation). Showing
 * all of them expanded would require excessive scrolling. Gmail's pattern
 * of collapsed messages with click-to-expand is familiar to users and
 * lets them quickly scan the conversation and focus on the messages
 * that matter.
 */
'use client';

import { useEffect, useState } from 'react';
import { useParams, useRouter } from 'next/navigation';
import { api } from '@/lib/api';
import { sanitizeEmailBody } from '@/lib/sanitize';
import type { Thread, Draft, Message } from '@/lib/types';

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
  // Track which messages are expanded. By default, only the last message
  // is expanded (like Gmail shows the most recent message open).
  const [expandedMessages, setExpandedMessages] = useState<Set<number>>(new Set());

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
        // Auto-expand the last message so the user sees the most recent content.
        if (response.data.messages && response.data.messages.length > 0) {
          const lastMsg = response.data.messages[response.data.messages.length - 1];
          setExpandedMessages(new Set([lastMsg.id]));
        }
      }
      setLoading(false);
    }
    fetchThread();
  }, [threadId]);

  function toggleMessage(msgId: number) {
    setExpandedMessages(prev => {
      const next = new Set(prev);
      if (next.has(msgId)) {
        next.delete(msgId);
      } else {
        next.add(msgId);
      }
      return next;
    });
  }

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

  function getSnippet(msg: Message): string {
    const cleaned = sanitizeEmailBody(msg.body_text);
    return cleaned.length > 120 ? cleaned.substring(0, 120) + '...' : cleaned;
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

  const userEmail = thread.gmail_account?.gmail_email || '';
  const isUserTheThreadFrom = thread.from_email.toLowerCase() === userEmail.toLowerCase();

  // Determine display name for each message.
  // If the thread's from_email is the user's own email (first fetched
  // message was outbound), inbound messages show "External Sender".
  // Otherwise the thread's from_name has the correct external sender.
  function getMessageSender(msg: Message) {
    if (msg.direction === 'inbound') {
      if (isUserTheThreadFrom) {
        return { name: 'External Sender', label: 'sender', initial: 'E' };
      }
      return {
        name: thread!.from_name || thread!.from_email,
        label: 'sender',
        initial: (thread!.from_name?.[0] || thread!.from_email[0]).toUpperCase(),
      };
    }
    return {
      name: isUserTheThreadFrom ? (thread!.from_name || userEmail) : userEmail,
      label: 'you',
      initial: isUserTheThreadFrom
        ? (thread!.from_name?.[0] || userEmail[0] || 'Y').toUpperCase()
        : (userEmail[0] || 'Y').toUpperCase(),
    };
  }

  return (
    <div>
      {/* Header above both panels */}
      <button
        onClick={() => router.push('/dashboard')}
        className="text-sm text-blue-600 hover:text-blue-800 mb-4 inline-block cursor-pointer"
      >
        ← Back to inbox
      </button>

        <div className="mb-6">
          <h2 className="text-xl font-bold text-gray-900">{thread.subject}</h2>
          <div className="flex items-center gap-3 mt-2">
            {thread.classification && (
              <span className={`px-2 py-1 rounded-full text-xs font-medium ${
                thread.classification === 'interested' ? 'bg-green-100 text-green-800' :
                thread.classification === 'meeting_request' ? 'bg-blue-100 text-blue-800' :
                thread.classification === 'unclear' ? 'bg-yellow-100 text-yellow-800' :
                'bg-gray-100 text-gray-800'
              }`}>
                {thread.classification.replace('_', ' ')}
              </span>
            )}
            {thread.confidence_score && (
              <span className="text-xs text-gray-400">
                {(parseFloat(thread.confidence_score) * 100).toFixed(0)}% confidence
              </span>
            )}
            <span className="text-xs text-gray-400">
              {thread.messages?.length || 0} messages
            </span>
          </div>
      </div>

      {/* Two-column layout: conversation left, draft sticky right */}
      <div className="flex gap-6 items-start">
        {/* Left: Conversation */}
        <div className="flex-1 min-w-0">
        <div className="bg-white border border-gray-200 rounded-lg overflow-hidden">
        {thread.messages && thread.messages.length > 0 ? (
          thread.messages.map((msg, index) => {
            const isExpanded = expandedMessages.has(msg.id);

            return (
              <div
                key={msg.id}
                className={index > 0 ? 'border-t-2 border-gray-200' : ''}
              >
                {/* Collapsed header — click to toggle */}
                <div
                  onClick={() => toggleMessage(msg.id)}
                  className={`w-full px-4 py-3 flex items-center gap-3 text-left hover:bg-gray-50 transition-colors cursor-pointer ${!isExpanded ? 'bg-gray-50/50' : ''}`}
                >
                  <div className={`w-8 h-8 rounded-full flex items-center justify-center text-xs font-medium text-white flex-shrink-0 ${
                    msg.direction === 'inbound' ? 'bg-blue-500' : 'bg-green-500'
                  }`}>
                    {getMessageSender(msg).initial}
                  </div>

                  <div className="flex-1 min-w-0">
                    <div className="flex items-center justify-between">
                      <div className="flex items-center gap-1.5">
                        <span className="text-sm font-medium text-gray-900">
                          {getMessageSender(msg).name}
                        </span>
                        <span className="text-xs text-gray-400">
                          ({getMessageSender(msg).label})
                        </span>
                      </div>
                      <span className="text-xs text-gray-400 flex-shrink-0 ml-2">
                        {msg.received_at ? new Date(msg.received_at).toLocaleDateString(undefined, {
                          month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit'
                        }) : ''}
                      </span>
                    </div>

                  {!isExpanded && (
                      <p className="text-sm text-gray-500 truncate mt-0.5">
                        {getSnippet(msg)}
                      </p>
                    )}
                  </div>

                  <span className="text-gray-400 text-xs flex-shrink-0">
                    {isExpanded ? '▼' : '▶'}
                  </span>
                </div>

                {isExpanded && (
                  <div className="px-4 pb-4 pl-16">
                    <div className="text-sm text-gray-900 whitespace-pre-wrap leading-relaxed">
                      {(() => {
                        const lines = sanitizeEmailBody(msg.body_text).split('\n');
                        // Detect where quoted/previous content begins.
                        // Different email clients format quotes differently:
                        //   - Gmail: "On <date> <person> wrote:"
                        //   - Outlook: "From: ... Sent: ... To: ..."
                        //   - Generic: "-----Original Message-----"
                        //   - Plain text: lines starting with >
                        let quotedStartIndex = -1;
                        for (let i = 0; i < lines.length; i++) {
                          const trimmed = lines[i].trim();
                          if (
                            /^On .+ wrote:$/i.test(trimmed) ||
                            /^-{3,}\s*Original Message\s*-{3,}$/i.test(trimmed) ||
                            /^From:.*Sent:.*$/i.test(trimmed) ||
                            (trimmed.startsWith('From:') && i + 1 < lines.length && lines[i + 1].trim().startsWith('Sent:'))
                          ) {
                            quotedStartIndex = i;
                            break;
                          }
                        }

                        return lines.map((line, i) => {
                          const isQuoted =
                            line.trimStart().startsWith('>') ||
                            (quotedStartIndex >= 0 && i >= quotedStartIndex);
                          return (
                            <span key={i} className={isQuoted ? 'text-purple-600' : ''}>
                              {line}{'\n'}
                            </span>
                          );
                        });
                      })()}
                    </div>
                  </div>
                )}
              </div>
            );
          })
        ) : (
          <div className="p-4 text-center text-gray-500 text-sm">No messages in this thread.</div>
        )}
      </div>
      </div>

        {/* Right: Draft reply — sticky, top-aligned with conversation */}
        <div className="w-[480px] flex-shrink-0 sticky top-6 self-start">
        {draft ? (
          <div className="bg-white border border-gray-200 rounded-lg">
            <div className="px-4 py-3 border-b border-gray-100 flex justify-between items-center">
              <div className="flex items-center gap-2">
                <span className="text-sm font-medium text-gray-900">Draft Reply</span>
                <span className={`px-2 py-0.5 rounded-full text-xs font-medium ${
                  draft.status === 'generated' ? 'bg-orange-100 text-orange-800' :
                  draft.status === 'approved' ? 'bg-blue-100 text-blue-800' :
                  draft.status === 'sent' ? 'bg-green-100 text-green-800' :
                  'bg-gray-100 text-gray-600'
                }`}>
                  {draft.status.charAt(0).toUpperCase() + draft.status.slice(1)}
                </span>
              </div>
              <span className="text-xs text-gray-500">Revision {draft.revision}</span>
            </div>

            <div className="p-4">
              {editMode ? (
                <textarea
                  value={editText}
                  onChange={(e) => setEditText(e.target.value)}
                  className="w-full h-48 p-3 text-sm text-gray-900 border border-gray-300 rounded-md focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                />
              ) : (
                <div className="text-sm text-gray-800 whitespace-pre-wrap leading-relaxed min-h-[6rem]">
                  {draft.body_text}
                </div>
              )}
            </div>

            <div className="px-4 py-3 border-t border-gray-100 flex gap-2">
              {draft.status === 'generated' && (
                <>
                  {editMode ? (
                    <>
                      <button
                        onClick={() => handleDraftAction('edit', { body_text: editText })}
                        disabled={actionLoading === 'edit'}
                        className="px-4 py-2 text-sm font-medium text-white bg-blue-600 rounded-md hover:bg-blue-700 disabled:opacity-50 cursor-pointer"
                      >
                        {actionLoading === 'edit' ? 'Saving...' : 'Save'}
                      </button>
                      <button
                        onClick={() => { setEditMode(false); setEditText(draft.body_text); }}
                        className="px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-100 rounded-md cursor-pointer"
                      >
                        Cancel
                      </button>
                    </>
                  ) : (
                    <>
                      <button
                        onClick={() => handleDraftAction('approve')}
                        disabled={actionLoading === 'approve'}
                        className="px-4 py-2 text-sm font-medium text-white bg-green-600 rounded-md hover:bg-green-700 disabled:opacity-50 cursor-pointer"
                      >
                        {actionLoading === 'approve' ? 'Approving...' : 'Approve'}
                      </button>
                      <button
                        onClick={() => setEditMode(true)}
                        className="px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-100 rounded-md cursor-pointer"
                      >
                        Edit
                      </button>
                      <button
                        onClick={() => handleDraftAction('discard')}
                        disabled={actionLoading === 'discard'}
                        className="px-4 py-2 text-sm font-medium text-red-600 hover:bg-red-50 rounded-md cursor-pointer"
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
                  className="px-4 py-2 text-sm font-medium text-white bg-green-600 rounded-md hover:bg-green-700 disabled:opacity-50 cursor-pointer"
                >
                  {actionLoading === 'send' ? 'Sending...' : 'Send via Gmail'}
                </button>
              )}

              {draft.status === 'sent' && draft.sent_at && (
                <p className="text-sm text-green-600 py-2">
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
                : 'No draft available for this thread.'}
            </p>
          </div>
        )}
        </div>
      </div>
    </div>
  );
}
