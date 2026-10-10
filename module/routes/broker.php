<?php

use HytaleHosting\Core\Http\Controllers\BrokerController;
use Illuminate\Support\Facades\Route;

// Mounted at /hytale-broker. Called by game server containers, which authenticate
// with their per-server bearer token instead of a session cookie, so CSRF
// protection does not apply (and could not work) here.
Route::post('/v1/session', BrokerController::class)
    ->withoutMiddleware([
        'Pterodactyl\Http\Middleware\VerifyCsrfToken',
        'Illuminate\Foundation\Http\Middleware\VerifyCsrfToken',
        'Illuminate\Foundation\Http\Middleware\ValidateCsrfToken',
        'Illuminate\Foundation\Http\Middleware\PreventRequestForgery',
    ])
    ->name('session');
