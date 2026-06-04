<?php

use App\Http\Controllers\Auth\GoogleAuthController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// -------------------------------------------------------------------------
// Google OAuth Routes
// -------------------------------------------------------------------------
// WHY web routes (not API routes): OAuth uses browser redirects. The user
// clicks "Connect Gmail", gets redirected to Google's consent screen, then
// Google redirects back to our callback URL. This is a browser flow that
// requires session state (for CSRF protection via the `state` parameter)
// and cookie support, both provided by the web middleware stack.
//
// WHY auth middleware: Only logged-in users should be able to connect Gmail
// accounts. The callback stores the OAuth tokens associated with the
// authenticated user's ID. Without auth, we wouldn't know who to associate
// the tokens with.
// -------------------------------------------------------------------------
Route::middleware('auth')->group(function () {
    Route::get('/auth/google/redirect', [GoogleAuthController::class, 'redirect'])
        ->name('google.redirect');
    Route::get('/auth/google/callback', [GoogleAuthController::class, 'callback'])
        ->name('google.callback');
});
