/**
 * API Client
 *
 * PURPOSE:
 * Shared fetch wrapper for calling the Laravel API from the Next.js frontend.
 * Every API call goes through this module so we handle authentication,
 * CSRF tokens, error formatting, and base URL configuration in one place.
 *
 * WHY a custom wrapper:
 * The native fetch API is built into every browser and Node.js 18+.
 * It needs no npm dependency, no bundle size cost, and no version
 * compatibility issues. For our use case (JSON REST calls with cookies),
 * fetch does everything we need. A library would add complexity
 * without adding capability.
 *
 * WHY credentials: 'include':
 * Sanctum SPA authentication uses cookies (session + XSRF token).
 * By default, fetch does not send cookies on cross-origin requests.
 * Setting credentials: 'include' tells the browser to attach cookies
 * when calling localhost:8000 from localhost:3000. Without this,
 * every API call would return "Unauthenticated".
 */

// The Laravel API URL. Reads from the Next.js environment variable
// set in docker-compose.yml (NEXT_PUBLIC_API_URL=http://localhost:8000).
// Falls back to localhost:8000 for local development outside Docker.
const API_BASE_URL = process.env.NEXT_PUBLIC_API_URL || 'http://localhost:8000';

/**
 * Typed API response wrapper.
 *
 * PURPOSE:
 * Every API call returns either data (success) or an error message
 * (failure). This type forces callers to handle both cases explicitly,
 * preventing silent failures where an error response is treated as data.
 */
type ApiResponse<T> = {
  data: T;
  error: null;
} | {
  data: null;
  error: string;
};

/**
 * Make an authenticated API request to the Laravel backend.
 *
 * PURPOSE:
 * Single entry point for all API calls. Handles:
 *   - Base URL prefixing (callers pass '/api/threads', not the full URL)
 *   - JSON content type headers
 *   - Cookie credentials for Sanctum auth
 *   - Response parsing and error extraction
 *   - Consistent error format for the UI to display
 *
 * @param endpoint  API path starting with '/' (e.g., '/api/threads')
 * @param options   Standard fetch options (method, body, headers, etc.)
 * @returns         Typed response with either data or error message
 */
/**
 * Read the XSRF-TOKEN cookie value.
 *
 * PURPOSE:
 * Laravel's CSRF protection expects the token as an X-XSRF-TOKEN header,
 * not just as a cookie. The browser stores the cookie from /sanctum/csrf-cookie,
 * but fetch() doesn't automatically convert cookies to headers. We read
 * the cookie manually and attach it as a header on every request.
 *
 * WHY decodeURIComponent: Laravel encrypts the XSRF token and URL-encodes
 * it before setting the cookie. The browser stores the encoded value.
 * We must decode it before sending as a header, otherwise Laravel rejects
 * it because the encrypted value doesn't match after double-encoding.
 */
function getXsrfToken(): string | null {
  if (typeof document === 'undefined') return null;
  const match = document.cookie.match(/XSRF-TOKEN=([^;]+)/);
  return match ? decodeURIComponent(match[1]) : null;
}

export async function apiRequest<T>(
  endpoint: string,
  options: RequestInit = {}
): Promise<ApiResponse<T>> {
  try {
    // Read the XSRF token from the cookie and attach as a header.
    const xsrfToken = getXsrfToken();
    const headers: Record<string, string> = {
      'Content-Type': 'application/json',
      'Accept': 'application/json',
    };
    if (xsrfToken) {
      headers['X-XSRF-TOKEN'] = xsrfToken;
    }

    const response = await fetch(`${API_BASE_URL}${endpoint}`, {
      ...options,
      headers: {
        ...headers,
        ...options.headers,
      },
      credentials: 'include',
    });

    // Parse the response body. Laravel always returns JSON for API routes
    // (thanks to the Accept: application/json header).
    const body = await response.json();

    if (!response.ok) {
      // Laravel returns validation errors as { message: '...', errors: {...} }
      // and auth errors as { message: 'Unauthenticated.' }.
      // We extract the message for display in the UI.
      return {
        data: null,
        error: body.message || `Request failed with status ${response.status}`,
      };
    }

    return { data: body as T, error: null };
  } catch (err) {
    // Network errors (server down, DNS failure, CORS blocked).
    // These don't have a response body to parse.
    return {
      data: null,
      error: err instanceof Error ? err.message : 'An unexpected error occurred',
    };
  }
}

// -------------------------------------------------------------------------
// Convenience methods for common HTTP verbs.
// These reduce boilerplate in components:
//   const { data } = await api.get('/api/threads');
// instead of:
//   const { data } = await apiRequest('/api/threads', { method: 'GET' });
// -------------------------------------------------------------------------

export const api = {
  get: <T>(endpoint: string) =>
    apiRequest<T>(endpoint, { method: 'GET' }),

  post: <T>(endpoint: string, body?: unknown) =>
    apiRequest<T>(endpoint, {
      method: 'POST',
      body: body ? JSON.stringify(body) : undefined,
    }),

  put: <T>(endpoint: string, body?: unknown) =>
    apiRequest<T>(endpoint, {
      method: 'PUT',
      body: body ? JSON.stringify(body) : undefined,
    }),

  delete: <T>(endpoint: string) =>
    apiRequest<T>(endpoint, { method: 'DELETE' }),
};
