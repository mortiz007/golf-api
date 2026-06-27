<?php

declare(strict_types=1);

use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\ListingController;
use Illuminate\Support\Facades\Route;

/*
 * Public API routes (SPECS §4).
 *
 * No authentication: anyone can browse the public listing (SPECS §4.4).
 */
Route::get('/listings', [ListingController::class, 'index'])->name('listings.index');

/*
 * Authenticated API routes (SPECS §4).
 *
 * Sanctum bearer auth (401 without a valid token) + per-user throttle
 * 60 req/min (429 when exceeded, decision #23).
 */
Route::middleware(['auth:sanctum', 'throttle:60,1'])->group(function (): void {
    // Listing creation has a stricter per-user daily cap on top of the
    // group throttle (named limiter "listing-creation", config/listings.php).
    Route::post('/listings', [ListingController::class, 'store'])
        ->middleware('throttle:listing-creation')
        ->name('listings.store');
    Route::patch('/listings/{id}', [ListingController::class, 'update'])->name('listings.update');
    Route::delete('/listings/{id}', [ListingController::class, 'destroy'])->name('listings.destroy');
    Route::get('/audit-logs', [AuditLogController::class, 'index'])->name('audit-logs.index');
});
