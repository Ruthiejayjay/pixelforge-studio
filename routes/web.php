<?php

use App\Http\Controllers\Admin\ReportController;
use App\Http\Controllers\CreditsController;
use App\Http\Controllers\GenerationController;
use App\Http\Controllers\PixelforgeWebhookController;
use App\Http\Controllers\StudioController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    if (auth()->check()) {
        return to_route('dashboard');
    }
})->name('home');

Route::middleware(['auth'])->group(function () {
    Route::get('dashboard', [StudioController::class, 'index'])->name('dashboard');
    Route::post('generations', [GenerationController::class, 'store'])->name('generations.store');
    Route::post('generations/{generation}/refresh', [GenerationController::class, 'refresh'])->name('generations.refresh');

    Route::get('credits', [CreditsController::class, 'index'])->name('credits.index');
    Route::post('credits/purchase', [CreditsController::class, 'purchase'])->name('credits.purchase');
    Route::middleware(['admin'])->prefix('admin')->name('admin.')->group(function () {
        Route::get('report', [ReportController::class, 'index'])->name('report');
    });
});

Route::post('/webhooks/pixelforge', PixelforgeWebhookController::class)
    ->name('webhooks.pixelforge');

require __DIR__ . '/settings.php';
require __DIR__ . '/auth.php';
