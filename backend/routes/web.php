<?php

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\GoogleAuthController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// -------------------------------------------------------------------------
// Authentication Routes (Session-Based)
// -------------------------------------------------------------------------
// WHY web routes (not API routes): Register, login, and logout use
// Laravel sessions for authentication. The web middleware stack provides
// session handling and CSRF protection. API routes don't include session
// middleware by default, which causes "Session store not set on request."
//
// WHY no auth middleware on register/login: These endpoints create the
// session. Requiring an existing session to create one is a contradiction.
// Logout and refresh require auth because they operate on an existing session.
// -------------------------------------------------------------------------
Route::post('/register', [AuthController::class, 'register'])->name('auth.register');
Route::post('/login', [AuthController::class, 'login'])->name('auth.login');

Route::middleware('auth')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout'])->name('auth.logout');
    Route::post('/refresh', [AuthController::class, 'refresh'])->name('auth.refresh');
    Route::get('/user', [AuthController::class, 'user'])->name('auth.user');
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
