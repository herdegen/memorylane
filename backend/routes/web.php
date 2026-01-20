<?php

use App\Http\Controllers\MediaController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// Dashboard
Route::get('/', function () {
    return Inertia::render('Dashboard');
})->name('dashboard');

// Media routes (will require authentication in future)
Route::prefix('media')->name('media.')->group(function () {
    Route::get('/', [MediaController::class, 'index'])->name('index');
    Route::get('/upload', [MediaController::class, 'create'])->name('create');
    Route::post('/', [MediaController::class, 'store'])->name('store');
    Route::get('/{media}', [MediaController::class, 'show'])->name('show');
    Route::delete('/{media}', [MediaController::class, 'destroy'])->name('destroy');
    Route::get('/{media}/download', [MediaController::class, 'download'])->name('download');
});

// Health check endpoint for Docker
Route::get('/health', function () {
    return response()->json([
        'status' => 'healthy',
        'app' => config('app.name'),
        'version' => '1.0.0',
    ]);
});
