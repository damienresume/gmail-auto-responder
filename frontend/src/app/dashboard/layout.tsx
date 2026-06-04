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
 */

import Link from 'next/link';

export default function DashboardLayout({
  children,
}: {
  children: React.ReactNode;
}) {
  return (
    <div className="flex min-h-screen bg-gray-50">
      {/* Sidebar navigation */}
      <nav className="w-64 bg-white border-r border-gray-200 p-6 flex flex-col gap-2">
        <h1 className="text-lg font-bold text-gray-900 mb-6">
          Gmail Auto-Responder
        </h1>

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

        {/* Connect Gmail button at the bottom of the sidebar. */}
        <div className="mt-auto pt-6 border-t border-gray-200">
          <a
            href={`${process.env.NEXT_PUBLIC_API_URL || 'http://localhost:8000'}/auth/google/redirect`}
            className="block w-full px-3 py-2 text-center rounded-md text-sm font-medium text-white bg-blue-600 hover:bg-blue-700"
          >
            Connect Gmail
          </a>
        </div>
      </nav>

      {/* Main content area where child pages render. */}
      <main className="flex-1 p-8">
        {children}
      </main>
    </div>
  );
}
