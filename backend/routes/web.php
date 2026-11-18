<?php

use App\Http\Controllers\Web\GoogleAuthController;
use App\Http\Controllers\Web\SitemapController;
use App\Http\Controllers\Web\UnsubscribeController;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => response()->json(['name' => config('lms.brand.name'), 'api' => url('/api/v1')]));

Route::get('/sitemap.xml', SitemapController::class)->name('sitemap');

// Google OAuth for the web SPA (needs the session, so it lives on the web stack).
Route::middleware('throttle:auth')->group(function () {
    Route::get('/auth/google/redirect', [GoogleAuthController::class, 'redirect'])->name('auth.google.redirect');
    Route::get('/auth/google/callback', [GoogleAuthController::class, 'callback'])->name('auth.google.callback');
});

// Email unsubscribe (signed links; POST = RFC 8058 one-click from mail clients).
Route::get('/unsubscribe/{user}/{event}', [UnsubscribeController::class, 'show'])->middleware('signed')->name('notifications.unsubscribe');
Route::post('/unsubscribe/{user}/{event}', [UnsubscribeController::class, 'store'])->middleware('signed')
    ->withoutMiddleware(ValidateCsrfToken::class)->name('notifications.unsubscribe.store');
