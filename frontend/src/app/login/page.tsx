/**
 * Login Page (/login)
 *
 * PURPOSE:
 * Authenticates the user with email and password via the Laravel API.
 * On success, Sanctum sets a session cookie and the user is redirected
 * to the dashboard. On failure, an error message is shown.
 *
 * WHY a separate page (not a modal):
 * Login is a distinct user flow, not an action within the dashboard.
 * A dedicated page has its own URL (/login) which means:
 *   - The browser back button works correctly.
 *   - Laravel can redirect unauthenticated users here directly.
 *   - Bookmarking and sharing the login URL works.
 */
'use client';

import { useState } from 'react';
import { useRouter } from 'next/navigation';
import Link from 'next/link';
import { api } from '@/lib/api';

const API_BASE = process.env.NEXT_PUBLIC_API_URL || 'http://localhost:8000';

export default function LoginPage() {
  const router = useRouter();
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [loading, setLoading] = useState(false);

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    setError(null);
    setLoading(true);

    // WHY fetch CSRF cookie first: Sanctum SPA auth requires a valid
    // XSRF token before any POST request. The /sanctum/csrf-cookie
    // endpoint sets the XSRF-TOKEN cookie, which the browser sends
    // back as the X-XSRF-TOKEN header on the login request. Without
    // this, Laravel rejects the login with a 419 (CSRF token mismatch).
    try {
      await fetch(`${API_BASE}/sanctum/csrf-cookie`, {
        credentials: 'include',
      });

    
      const response = await api.post<{ user: { id: number; name: string; email: string } }>(
        '/login',
        { email, password }
      );

      if (response.error) {
        setError(response.error);
      } else {
        router.push('/dashboard');
      }
    } catch {
      setError('An unexpected error occurred. Please try again.');
    }

    setLoading(false);
  }

  return (
    <div className="min-h-screen flex items-center justify-center bg-gray-50 px-4">
      <div className="w-full max-w-md">
        <h1 className="text-2xl font-bold text-gray-900 text-center mb-8">
          Gmail Auto-Responder
        </h1>

        <div className="bg-white rounded-lg border border-gray-200 p-8 shadow-sm">
          <h2 className="text-lg font-semibold text-gray-900 mb-6">Log in</h2>

          {error && (
            <div className="bg-red-50 border border-red-200 rounded-md p-3 mb-4 text-sm text-red-800">
              {error}
            </div>
          )}

          <form onSubmit={handleSubmit} className="space-y-4">
            <div>
              <label htmlFor="email" className="block text-sm font-medium text-gray-700 mb-1">
                Email
              </label>
              <input
                id="email"
                type="email"
                required
                value={email}
                onChange={(e) => setEmail(e.target.value)}
                className="w-full px-3 py-2 border border-gray-300 rounded-md text-sm text-gray-900 focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                placeholder="you@example.com"
              />
            </div>

            <div>
              <label htmlFor="password" className="block text-sm font-medium text-gray-700 mb-1">
                Password
              </label>
              <input
                id="password"
                type="password"
                required
                value={password}
                onChange={(e) => setPassword(e.target.value)}
                className="w-full px-3 py-2 border border-gray-300 rounded-md text-sm text-gray-900 focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                placeholder="••••••••"
              />
            </div>

            <button
              type="submit"
              disabled={loading}
              className="w-full py-2 px-4 bg-blue-600 text-white text-sm font-medium rounded-md hover:bg-blue-700 disabled:opacity-50 disabled:cursor-not-allowed"
            >
              {loading ? 'Logging in...' : 'Log in'}
            </button>
          </form>

          <p className="mt-4 text-center text-sm text-gray-500">
            Don&apos;t have an account?{' '}
            <Link href="/register" className="text-blue-600 hover:text-blue-800 font-medium">
              Register
            </Link>
          </p>
        </div>
      </div>
    </div>
  );
}
