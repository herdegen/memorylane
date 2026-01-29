<?php

use App\Http\Controllers\MediaController;
use App\Http\Controllers\TagController;
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

// Tag routes
Route::prefix('tags')->name('tags.')->group(function () {
    Route::get('/', [TagController::class, 'index'])->name('index');
    Route::post('/', [TagController::class, 'store'])->name('store');
    Route::put('/{tag}', [TagController::class, 'update'])->name('update');
    Route::delete('/{tag}', [TagController::class, 'destroy'])->name('destroy');

    // Attach/detach tags to/from media
    Route::post('/attach', [TagController::class, 'attach'])->name('attach');
    Route::post('/detach', [TagController::class, 'detach'])->name('detach');

    // Get tags for a specific media
    Route::get('/media/{media}', [TagController::class, 'mediaTags'])->name('media');
});

// Health check endpoint for Docker
Route::get('/health', function () {
    return response()->json([
        'status' => 'healthy',
        'app' => config('app.name'),
        'version' => '1.0.0',
    ]);
});
