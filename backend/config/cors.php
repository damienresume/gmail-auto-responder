<?php

/**
 * CORS Configuration
 *
 * PURPOSE:
 * Controls which origins (domains) can call our API from a browser.
 * Without this, the Next.js dashboard at localhost:3000 would get
 * blocked by the browser's Same-Origin Policy when calling the
 * Laravel API at localhost:8000.
 *
 * WHY these settings:
 *   - allowed_origins uses the FRONTEND_URL env var so it works in
 *     any environment (local, staging, production) without code changes.
 *   - supports_credentials is true because Sanctum's SPA authentication
 *     uses cookies. The browser only sends cookies cross-origin if
 *     the server explicitly allows credentials.
 *   - allowed_methods includes GET, POST, PUT, DELETE for full REST
 *     support plus OPTIONS for preflight requests.
 *   - paths is limited to 'api/*' and 'sanctum/csrf-cookie'. We don't
 *     expose CORS on web routes (OAuth callbacks) because those are
 *     browser redirects, not AJAX calls.
 *
 * SECURITY:
 *   - We do NOT use '*' for allowed_origins. That would allow any website
 *     to call our API. Instead, only the configured frontend URL is allowed.
 *   - supports_credentials + wildcard origin is rejected by browsers
 *     (it's a spec violation), so being explicit about origins is required
 *     anyway when credentials are enabled.
 */

return [

    // Only allow CORS on API routes and the Sanctum CSRF cookie endpoint.
    // Web routes (OAuth redirects) don't need CORS because the browser
    // navigates to them directly, not via fetch/XMLHttpRequest.
    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['GET', 'POST', 'PUT', 'DELETE', 'OPTIONS'],

    // WHY env-based: Different environments have different frontend URLs.
    // Local: http://localhost:3000, Staging: https://staging.example.com, etc.
    // Using env allows each environment to set its own allowed origin
    // without modifying this config file.
    'allowed_origins' => [env('FRONTEND_URL', 'http://localhost:3000')],

    'allowed_origins_patterns' => [],

    // WHY specific headers instead of '*':
    // Listing exact headers documents what the frontend actually sends.
    // '*' would work but hides which headers are expected, making it
    // harder to debug CORS issues or audit API usage.
    'allowed_headers' => [
        'Content-Type',
        'Authorization',
        'X-Requested-With',
        'Accept',
        'Origin',
        'X-XSRF-TOKEN',
    ],

    'exposed_headers' => [],

    'max_age' => 0,

    // WHY true: Sanctum SPA auth sends the session cookie and XSRF token
    // with every API request. Browsers only include cookies in cross-origin
    // requests when Access-Control-Allow-Credentials is set to true.
    // Without this, the frontend would always get "Unauthenticated"
    // even after logging in.
    'supports_credentials' => true,
];
