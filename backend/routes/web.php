<?php

use App\Http\Controllers\AlbumController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\MediaController;
use App\Http\Controllers\PersonController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\TagController;
use App\Http\Controllers\MapController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// Dashboard
Route::get('/', function () {
    return auth()->check() ? redirect()->route('dashboard') : redirect()->route('login');
});

// Authentication routes
Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login']);
});

Route::post('/logout', [AuthController::class, 'logout'])->name('logout')->middleware('auth');

// Protected routes
Route::middleware('auth')->group(function () {
    // Dashboard
    Route::get('/dashboard', function () {
        return Inertia::render('Dashboard');
    })->name('dashboard');

    // Profile routes
    Route::prefix('profile')->name('profile.')->group(function () {
        Route::get('/', [ProfileController::class, 'show'])->name('show');
        Route::get('/edit', [ProfileController::class, 'edit'])->name('edit');
        Route::put('/', [ProfileController::class, 'update'])->name('update');
        Route::put('/password', [ProfileController::class, 'updatePassword'])->name('password');
    });

    // Media routes
    Route::prefix('media')->name('media.')->group(function () {
        Route::get('/', [MediaController::class, 'index'])->name('index');
        Route::get('/upload', [MediaController::class, 'create'])->name('create');
        Route::post('/', [MediaController::class, 'store'])->name('store');
        Route::get('/{media}', [MediaController::class, 'show'])->name('show');
        Route::put('/{media}', [MediaController::class, 'update'])->name('update');
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

    // Map routes
    Route::prefix('map')->name('map.')->group(function () {
        Route::get('/', [MapController::class, 'index'])->name('index');
        Route::get('/media', [MapController::class, 'getGeolocatedMedia'])->name('media');
        Route::post('/media/{media}/geolocation', [MapController::class, 'updateGeolocation'])->name('updateGeolocation');
        Route::delete('/media/{media}/geolocation', [MapController::class, 'removeGeolocation'])->name('removeGeolocation');
        Route::get('/search', [MapController::class, 'searchLocation'])->name('searchLocation');
        Route::get('/nearby', [MapController::class, 'getNearbyMedia'])->name('nearby');
    });

    // Album routes
    Route::prefix('albums')->name('albums.')->group(function () {
        Route::get('/', [AlbumController::class, 'index'])->name('index');
        Route::post('/', [AlbumController::class, 'store'])->name('store');
        Route::get('/{album}', [AlbumController::class, 'show'])->name('show');
        Route::put('/{album}', [AlbumController::class, 'update'])->name('update');
        Route::delete('/{album}', [AlbumController::class, 'destroy'])->name('destroy');

        // Media management
        Route::post('/{album}/media', [AlbumController::class, 'addMedia'])->name('addMedia');
        Route::delete('/{album}/media', [AlbumController::class, 'removeMedia'])->name('removeMedia');
        Route::put('/{album}/media/reorder', [AlbumController::class, 'reorderMedia'])->name('reorderMedia');

        // Sharing
        Route::post('/{album}/share', [AlbumController::class, 'generateShareToken'])->name('generateShare');
        Route::delete('/{album}/share', [AlbumController::class, 'revokeShareToken'])->name('revokeShare');
    });

    // People routes
    Route::prefix('people')->name('people.')->group(function () {
        Route::get('/', [PersonController::class, 'index'])->name('index');
        Route::post('/', [PersonController::class, 'store'])->name('store');
        Route::get('/{person}', [PersonController::class, 'show'])->name('show');
        Route::put('/{person}', [PersonController::class, 'update'])->name('update');
        Route::delete('/{person}', [PersonController::class, 'destroy'])->name('destroy');
        Route::post('/attach', [PersonController::class, 'attachToMedia'])->name('attach');
        Route::post('/detach', [PersonController::class, 'detachFromMedia'])->name('detach');
    });
});

// Public shared album route
Route::get('/albums/shared/{token}', [AlbumController::class, 'showShared'])->name('albums.shared');

// Health check endpoint for Docker
Route::get('/health', function () {
    return response()->json([
        'status' => 'healthy',
        'app' => config('app.name'),
        'version' => '1.0.0',
    ]);
});
