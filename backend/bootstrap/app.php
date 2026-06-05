<?php

use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // WHY statefulApi: Enables cookie-based session auth for the API
        // routes when called from the same domain (Next.js on localhost:3000).
        // This works with Sanctum's SPA authentication without requiring
        // API tokens for the dashboard frontend.
        $middleware->statefulApi();

        // WHY redirectGuestsTo: When an unauthenticated user hits a route
        // protected by the 'auth' middleware, Laravel needs to know where
        // to send them. Without this, it looks for a 'login' named route
        // and throws RouteNotFoundException if one doesn't exist.
        // For API requests (Accept: application/json), Laravel returns 401
        // automatically. For browser requests, we redirect to the frontend.
        $middleware->redirectGuestsTo(fn (Request $request) =>
            $request->expectsJson()
                ? null
                : config('app.frontend_url', 'http://localhost:3000') . '/login'
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // WHY custom unauthenticated handler: By default, Laravel tries to
        // redirect to a 'login' named route for web requests. Since our
        // frontend is a separate Next.js app, we redirect to the frontend
        // login page instead. For API requests, we return a clean JSON 401.
        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Unauthenticated.'], 401);
            }
            return redirect(config('app.frontend_url', 'http://localhost:3000') . '/login');
        });
    })->create();
