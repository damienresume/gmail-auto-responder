<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

/**
 * AuthController
 *
 * PURPOSE:
 * Handles user registration, login, logout, token refresh, and profile
 * retrieval for the Next.js SPA frontend. Uses Sanctum's cookie-based
 * session auth so the frontend doesn't need to manage API tokens manually.
 *
 * WHY session auth:
 * The frontend and backend run on the same machine (localhost). Session
 * auth with cookies is simpler for SPAs because:
 *   - No token storage logic in the frontend (cookies are automatic).
 *   - No token refresh logic on the client (sessions extend on activity).
 *   - CSRF protection is built-in (Sanctum handles XSRF tokens).
 *   - Logout invalidates the session server-side. With tokens, you'd need
 *     to maintain a blocklist or wait for expiry.
 *
 * WHY:
 * Even though session auth handles most cases, there are scenarios where
 * sessions need explicit refreshing:
 *   - Long-lived dashboard tabs that stay open for hours. The session
 *     cookie expires based on SESSION_LIFETIME (default 120 minutes).
 *   - Mobile or third-party API consumers that use Sanctum API tokens
 *     instead of session cookies.
 * The refresh endpoint extends the session without requiring the user
 * to re-enter credentials.
 *
 * SECURITY:
 *   - Passwords hashed with bcrypt (Laravel default, 12 rounds).
 *   - Registration validates password strength via Password rule.
 *   - Login uses Auth::attempt which is timing-safe (constant-time
 *     comparison prevents email enumeration via response timing).
 *   - Session regenerated on login to prevent session fixation attacks.
 *   - Logout invalidates session and regenerates CSRF token.
 *   - Refresh endpoint requires an existing valid session (authenticated).
 */
class AuthController extends Controller
{
    /**
     * Register a new user account.
     *
     * PURPOSE:
     * Creates a new user, logs them in immediately, and returns the user
     * data. Immediate login after registration avoids the extra step of
     * "now go log in with the account you just created."
     */
    public function register(Request $request): JsonResponse
    {
        // WHY invalidate first: If the user previously had an account that
        // was deleted (e.g., during development or account reset), their
        // browser still holds the old session cookie. That session in Redis
        // carries stale auth data referencing a non-existent user. Without
        // clearing it, Auth::login() tries to overwrite the stale session,
        // but Sanctum's session resolution can fail on the next request
        // because the session was originally created for a different user.
        // Invalidating first gives us a clean slate.
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
        ]);

        Auth::login($user);

        // WHY regenerate: Prevents session fixation attacks. The session ID
        // changes after login so an attacker who knew the pre-login session
        // ID cannot hijack the authenticated session.
        $request->session()->regenerate();

        return response()->json([
            'message' => 'Account created successfully.',
            'user' => $user->only(['id', 'name', 'email']),
        ], 201);
    }

    /**
     * Log in with existing credentials.
     *
     * PURPOSE:
     * Authenticates the user and creates a session. The browser receives
     * a session cookie that is sent with every subsequent request so the
     * user stays logged in across page navigations.
     *
     * WHY Auth::attempt:
     * It is timing-safe. It takes the same amount of time whether the
     * email exists or not, preventing attackers from enumerating valid
     * email addresses by measuring response times.
     */
    public function login(Request $request): JsonResponse
    {
        // WHY invalidate before attempting: Same reason as register —
        // clears any stale session data from a previously deleted user.
        // Without this, logging in after a user deletion can leave
        // conflicting auth state in the session.
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        $credentials = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (!Auth::attempt($credentials, $request->boolean('remember'))) {
            return response()->json([
                'message' => 'The provided credentials are incorrect.',
            ], 401);
        }

        $request->session()->regenerate();

        return response()->json([
            'message' => 'Logged in successfully.',
            'user' => Auth::user()->only(['id', 'name', 'email']),
        ]);
    }

    /**
     * Refresh the authenticated session.
     *
     * PURPOSE:
     * Extends the session lifetime without requiring the user to log in
     * again. The frontend can call this periodically (e.g., every 30
     * minutes) to prevent the session from expiring while the user is
     * actively using the dashboard.
     *
     * HOW it works:
     * Calling session()->regenerate() creates a new session ID with a
     * fresh expiry timestamp. The old session ID is invalidated. This
     * is the same mechanism used during login, but without re-checking
     * credentials because the user is already authenticated.
     *
     * WHY regenerate the session ID (not just touch the expiry):
     * Regenerating the ID is a security best practice. If an attacker
     * captured a session ID, it becomes useless after the next refresh.
     * This limits the window of exposure for stolen session cookies.
     *
     * WHEN to call this:
     *   - The frontend sets a timer (e.g., setInterval every 30 min).
     *   - On user activity after a period of inactivity.
     *   - Before performing a sensitive action (approve/send draft).
     */
    public function refresh(Request $request): JsonResponse
    {
        $request->session()->regenerate();

        return response()->json([
            'message' => 'Session refreshed.',
            'user' => $request->user()->only(['id', 'name', 'email']),
        ]);
    }

    /**
     * Log out the current user.
     *
     * PURPOSE:
     * Destroys the session server-side and invalidates the cookie.
     * After this, the session cookie is useless even if captured.
     *
     * WHY invalidate + regenerateToken:
     * invalidate() destroys the session data on the server.
     * regenerateToken() creates a new CSRF token so the old one
     * (which might be cached by the browser) cannot be reused.
     */
    public function logout(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json([
            'message' => 'Logged out successfully.',
        ]);
    }

    /**
     * Get the authenticated user's profile.
     *
     * PURPOSE:
     * The frontend calls this on page load to check if the user is
     * still logged in. If it returns 401 (session expired), the frontend
     * redirects to login. If it returns user data, the frontend shows
     * the dashboard. This avoids storing auth state in frontend memory
     * which would be lost on page refresh.
     */
    public function user(Request $request): JsonResponse
    {
        return response()->json($request->user()->only(['id', 'name', 'email']));
    }
}
