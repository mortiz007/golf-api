<?php

declare(strict_types=1);

use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\ListingController;
use Illuminate\Support\Facades\Route;

/*
 * Authenticated API routes (SPECS §4).
 *
 * Sanctum bearer auth (401 without a valid token) + per-user throttle
 * 60 req/min (429 when exceeded, decision #23).
 */
Route::middleware(['auth:sanctum', 'throttle:60,1'])->group(function (): void {
    Route::post('/listings', [ListingController::class, 'store'])->name('listings.store');
    Route::patch('/listings/{id}', [ListingController::class, 'update'])->name('listings.update');
    Route::delete('/listings/{id}', [ListingController::class, 'destroy'])->name('listings.destroy');
    Route::get('/audit-logs', [AuditLogController::class, 'index'])->name('audit-logs.index');
});
