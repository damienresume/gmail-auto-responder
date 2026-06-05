/**
 * Dashboard Layout
 *
 * PURPOSE:
 * Wraps all dashboard pages with a consistent navigation sidebar and
 * header. Uses Next.js App Router's nested layout pattern so the sidebar
 * persists across page navigations without re-rendering.
 *
 * WHY a nested layout under /dashboard:
 * The root layout (app/layout.tsx) wraps the entire app including the
 * landing page. The dashboard layout only wraps authenticated pages.
 * This separation means the landing page gets a clean layout while
 * dashboard pages get the sidebar navigation.
 *
 * WHY 'use client':
 * The logout button needs onClick handler and the useRouter hook for
 * redirect after logout. Auth check uses useEffect and useState.
 *
 * WHY an auth guard here:
 * Without it, the dashboard renders immediately after navigation and
 * the child pages make API calls that return 401 "Unauthenticated."
 * The user sees a confusing red error banner instead of being smoothly
 * redirected to login. The auth guard calls /user on mount to verify
 * the session is valid before rendering any dashboard content.
 */
'use client';

import { useEffect, useState } from 'react';
import { useRouter } from 'next/navigation';
import Link from 'next/link';
import { api } from '@/lib/api';

const API_BASE = process.env.NEXT_PUBLIC_API_URL || 'http://localhost:8000';

export default function DashboardLayout({
  children,
}: {
  children: React.ReactNode;
}) {
  const router = useRouter();
  const [authChecked, setAuthChecked] = useState(false);
  const [user, setUser] = useState<{ id: number; name: string; email: string } | null>(null);

  // Check if the user is authenticated on mount. If not, redirect to
  // login instead of rendering the dashboard and showing confusing
  // "Unauthenticated." errors from API calls.
  useEffect(() => {
    async function checkAuth() {
      const response = await api.get<{ id: number; name: string; email: string }>('/api/user');
      if (response.error || !response.data) {
        // Session expired or user not logged in — the API client's 401
        // handler will redirect to /login. We set authChecked to avoid
        // a flash of dashboard content.
        setAuthChecked(true);
        return;
      }
      setUser(response.data);
      setAuthChecked(true);
    }
    checkAuth();
  }, []);

  async function handleLogout() {
    await api.post('/logout');
    router.push('/login');
  }

  // Show a clean loading state while verifying auth. This prevents the
  // dashboard from flashing briefly before a redirect to login.
  if (!authChecked || !user) {
    return (
      <div className="min-h-screen flex items-center justify-center bg-gray-50">
        <p className="text-gray-500 text-sm">Loading...</p>
      </div>
    );
  }

  return (
    <div className="min-h-screen bg-gray-50">
      {/* Top header bar with branding, actions, and user controls */}
      <header className="bg-white border-b border-gray-200 px-6 py-3 flex items-center justify-between">
        <div className="flex items-center gap-6">
          <h1 className="text-lg font-bold text-gray-900">
            Gmail Auto-Responder
          </h1>
          <nav className="flex items-center gap-1">
            <Link
              href="/dashboard"
              className="px-3 py-2 rounded-md text-sm font-medium text-gray-700 hover:bg-gray-100 hover:text-gray-900"
            >
              Threads
            </Link>
            <Link
              href="/dashboard/drafts"
              className="px-3 py-2 rounded-md text-sm font-medium text-gray-700 hover:bg-gray-100 hover:text-gray-900"
            >
              Drafts
            </Link>
          </nav>
        </div>

        <div className="flex items-center gap-3">
          {/* Show the logged-in user's name for context */}
          <span className="text-sm text-gray-500">{user.name}</span>
          <a
            href={`${API_BASE}/auth/google/redirect`}
            className="px-4 py-2 rounded-md text-sm font-medium text-white bg-blue-600 hover:bg-blue-700"
          >
            Connect Gmail
          </a>
          <button
            onClick={handleLogout}
            className="px-4 py-2 rounded-md text-sm font-medium text-gray-700 bg-gray-100 hover:bg-gray-200"
          >
            Logout
          </button>
        </div>
      </header>

      {/* Main content area */}
      <main className="max-w-7xl mx-auto p-8">
        {children}
      </main>
    </div>
  );
}
