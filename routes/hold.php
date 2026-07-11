<?php

declare(strict_types=1);

use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Route;
use JamesGifford\Hold\Http\Controllers\HoldSignupController;
use JamesGifford\Hold\Http\Controllers\PreviewController;

/*
 * Package routes. The service provider loads this file inside a group that
 * applies the configured prefix and middleware, so the paths below are
 * relative to that prefix (e.g. 'signup' => /hold/signup).
 *
 * Signup is CSRF-exempt on purpose: both holding pages render before Laravel
 * starts the session (prelaunch runs as global middleware; the 503 view renders
 * during an aborted maintenance request), so neither can embed a CSRF token.
 * The honeypot field and per-IP rate limit guard the endpoint instead.
 */

Route::post('signup', [HoldSignupController::class, 'store'])
    ->name('hold.signup')
    ->withoutMiddleware([PreventRequestForgery::class]);

Route::get('preview', PreviewController::class)
    ->name('hold.preview')
    ->middleware('signed');
