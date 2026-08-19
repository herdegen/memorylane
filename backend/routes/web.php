<?php

use App\Http\Controllers\AlbumController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\FamilyTreeController;
use App\Http\Controllers\GedcomImportController;
use App\Http\Controllers\GooglePhotosController;
use App\Http\Controllers\LifeEventController;
use App\Http\Controllers\MediaController;
use App\Http\Controllers\HouseholdController;
use App\Http\Controllers\PersonController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\ShareTargetController;
use App\Http\Controllers\TakeoutImportController;
use App\Http\Controllers\UploadController;
use App\Http\Controllers\TagController;
use App\Http\Controllers\MapController;
use App\Http\Controllers\VisionController;
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

    // Connexion par lien magique
    Route::post('/login/magic', [AuthController::class, 'sendMagicLink'])->name('login.magic.send');
    Route::get('/login/magic/{user}', [AuthController::class, 'loginWithMagicLink'])
        ->middleware('signed')
        ->name('login.magic.verify');
});

Route::post('/logout', [AuthController::class, 'logout'])->name('logout')->middleware('auth');

// Protected routes
Route::middleware('auth')->group(function () {
    // Dashboard
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    // Recherche unifiée
    Route::get('/search', [SearchController::class, 'index'])->name('search');

    // Web Share Target (PWA) : « Partager → MemoryLane » depuis le téléphone
    Route::post('/share-target', [ShareTargetController::class, 'store'])->name('share-target');

    // Import Google Photos (Picker API)
    Route::prefix('google-photos')->name('google-photos.')->group(function () {
        Route::get('/', [GooglePhotosController::class, 'index'])->name('index');
        Route::get('/connect', [GooglePhotosController::class, 'connect'])->name('connect');
        Route::post('/session', [GooglePhotosController::class, 'createSession'])->name('session');
        Route::get('/session/status', [GooglePhotosController::class, 'sessionStatus'])->name('status');
        Route::post('/session/cancel', [GooglePhotosController::class, 'cancelSession'])->name('session.cancel');
        Route::post('/import', [GooglePhotosController::class, 'import'])->name('import');
        Route::get('/imported', [GooglePhotosController::class, 'importedStatus'])->name('imported');
    });
    Route::get('/auth/google/callback', [GooglePhotosController::class, 'callback'])->name('google-photos.callback');

    // Import Google Takeout (ZIP avec géolocalisation)
    Route::get('/takeout', [TakeoutImportController::class, 'index'])->name('takeout.index');
    Route::post('/takeout', [TakeoutImportController::class, 'store'])->name('takeout.store');

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
        // IDs de tous les médias du filtre courant (« tout sélectionner »).
        // Déclarée avant /{media} pour ne pas être capturée comme un id.
        Route::get('/ids', [MediaController::class, 'ids'])->name('ids');
        Route::get('/upload', [MediaController::class, 'create'])->name('create');
        Route::post('/', [MediaController::class, 'store'])->name('store');
        // Upload multipart direct S3 (gros fichiers / vidéos)
        Route::post('/uploads/initiate', [UploadController::class, 'initiate'])->name('uploads.initiate');
        Route::post('/uploads/status', [UploadController::class, 'status'])->name('uploads.status');
        Route::post('/uploads/part-url', [UploadController::class, 'partUrl'])->name('uploads.partUrl');
        Route::post('/uploads/complete', [UploadController::class, 'complete'])->name('uploads.complete');
        Route::post('/uploads/abort', [UploadController::class, 'abort'])->name('uploads.abort');
        // Modifications de masse depuis la galerie (sélection multiple)
        Route::post('/bulk/taken-at', [MediaController::class, 'bulkUpdateTakenAt'])->name('bulk.takenAt');
        Route::post('/bulk/geolocation', [MediaController::class, 'bulkUpdateGeolocation'])->name('bulk.geolocation');
        Route::get('/{media}', [MediaController::class, 'show'])->name('show');
        Route::put('/{media}', [MediaController::class, 'update'])->name('update');
        Route::delete('/{media}', [MediaController::class, 'destroy'])->name('destroy');
        Route::get('/{media}/download', [MediaController::class, 'download'])->name('download');
        // Porte d'entrée unique des <img>/<video> : auth + policy à chaque
        // chargement, puis 302 vers une présignée S3 très courte.
        Route::get('/{media}/file/{conversion?}', [MediaController::class, 'file'])->name('file');
        // Découpage d'une vidéo en clips (un Media par segment)
        Route::post('/{media}/clips', [MediaController::class, 'storeClips'])->name('storeClips');
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
        Route::post('/{album}/geolocate', [AlbumController::class, 'geolocate'])->name('geolocate');
        Route::post('/{album}/cover', [AlbumController::class, 'setCover'])->name('setCover');

        // Sharing (lien anonyme)
        Route::post('/{album}/share', [AlbumController::class, 'generateShareToken'])->name('generateShare');
        Route::delete('/{album}/share', [AlbumController::class, 'revokeShareToken'])->name('revokeShare');

        // Partage à des comptes choisis (accès restreint + délégation)
        Route::get('/{album}/access', [AlbumController::class, 'accessList'])->name('access.list');
        Route::get('/{album}/access/candidates', [AlbumController::class, 'grantCandidates'])->name('access.candidates');
        Route::post('/{album}/access', [AlbumController::class, 'grantAccess'])->name('access.grant');
        Route::delete('/{album}/access', [AlbumController::class, 'revokeAccess'])->name('access.revoke');
    });

    // Foyers (cercles familiaux) — appartenance ; le partage média arrive en 2b
    Route::prefix('households')->name('households.')->group(function () {
        Route::get('/', [HouseholdController::class, 'index'])->name('index');
        Route::post('/', [HouseholdController::class, 'store'])->name('store');
        Route::get('/{household}', [HouseholdController::class, 'show'])->name('show');
        Route::delete('/{household}', [HouseholdController::class, 'destroy'])->name('destroy');
        Route::post('/{household}/leave', [HouseholdController::class, 'leave'])->name('leave');

        Route::get('/{household}/members/candidates', [HouseholdController::class, 'inviteCandidates'])->name('members.candidates');
        Route::post('/{household}/members', [HouseholdController::class, 'invite'])->name('members.invite');
        Route::delete('/{household}/members/{user}', [HouseholdController::class, 'removeMember'])->name('members.remove');
    });

    // Vision AI routes
    Route::prefix('vision')->name('vision.')->group(function () {
        Route::get('/media/{media}/faces', [VisionController::class, 'faces'])->name('faces');
        Route::get('/media/{media}/image', [VisionController::class, 'image'])->name('image');
        Route::post('/media/{media}/faces', [VisionController::class, 'storeFaces'])->name('storeFaces');
        Route::post('/media/{media}/faces/add', [VisionController::class, 'addFace'])->name('addFace');
        Route::post('/faces/{detectedFace}/match', [VisionController::class, 'matchFace'])->name('matchFace');
        Route::post('/faces/{detectedFace}/dismiss', [VisionController::class, 'dismissFace'])->name('dismissFace');
        Route::post('/faces/{detectedFace}/reset', [VisionController::class, 'resetFace'])->name('resetFace');
        Route::get('/faces/{detectedFace}/suggest', [VisionController::class, 'suggest'])->name('suggest');
        Route::post('/faces/{detectedFace}/auto-match', [VisionController::class, 'autoMatch'])->name('autoMatch');
        Route::get('/pending', [VisionController::class, 'pending'])->name('pending');
        Route::get('/batch', fn () => Inertia::render('Vision/Batch'))->name('batch');
        Route::get('/media/{media}/labels', [VisionController::class, 'labels'])->name('labels');
        Route::post('/media/{media}/analyze', [VisionController::class, 'reanalyze'])->name('reanalyze');
        Route::get('/media/{media}/status', [VisionController::class, 'status'])->name('status');
    });

    // People routes
    Route::prefix('people')->name('people.')->group(function () {
        Route::get('/', [PersonController::class, 'index'])->name('index');
        Route::post('/', [PersonController::class, 'store'])->name('store');
        Route::get('/{person}', [PersonController::class, 'show'])->name('show');
        Route::get('/{person}/face-avatar', [PersonController::class, 'faceAvatar'])->name('faceAvatar');
        // Photo de profil explicite : servie à tout compte connecté (fiches
        // et arbre « publics entre comptes »), jamais en présigné long.
        Route::get('/{person}/avatar-image', [PersonController::class, 'avatarImage'])->name('avatarImage');
        Route::put('/{person}', [PersonController::class, 'update'])->name('update');
        Route::delete('/{person}', [PersonController::class, 'destroy'])->name('destroy');
        Route::post('/attach', [PersonController::class, 'attachToMedia'])->name('attach');
        Route::post('/detach', [PersonController::class, 'detachFromMedia'])->name('detach');

        // Family relationships
        Route::post('/{person}/parent', [PersonController::class, 'setParent'])->name('setParent');
        Route::delete('/{person}/parent', [PersonController::class, 'removeParent'])->name('removeParent');
        Route::post('/{person}/set-self', [PersonController::class, 'setSelf'])->name('setSelf');
        Route::post('/{person}/spouse', [PersonController::class, 'addSpouse'])->name('addSpouse');
        Route::delete('/{person}/spouse', [PersonController::class, 'removeSpouse'])->name('removeSpouse');
        Route::post('/{person}/child', [PersonController::class, 'addChild'])->name('addChild');

        // Frise de vie + moments
        Route::get('/{person}/timeline', [PersonController::class, 'timeline'])->name('timeline');
        Route::post('/{person}/events', [LifeEventController::class, 'store'])->name('events.store');
    });

    // Édition / suppression d'un moment de vie
    Route::put('/life-events/{lifeEvent}', [LifeEventController::class, 'update'])->name('life-events.update');
    Route::delete('/life-events/{lifeEvent}', [LifeEventController::class, 'destroy'])->name('life-events.destroy');

    // Family Tree routes
    Route::prefix('family-tree')->name('family-tree.')->group(function () {
        Route::get('/', [FamilyTreeController::class, 'index'])->name('index');
        Route::get('/data', [FamilyTreeController::class, 'treeData'])->name('data');
        Route::get('/data/{person}', [FamilyTreeController::class, 'subtree'])->name('subtree');

        // GEDCOM import — réservé aux admins (accès depuis le panneau admin)
        Route::middleware('admin')->group(function () {
            Route::get('/import', [GedcomImportController::class, 'index'])->name('import');
            Route::post('/import/upload', [GedcomImportController::class, 'upload'])->name('import.upload');
            Route::get('/import/{gedcomImport}/review', [GedcomImportController::class, 'review'])->name('import.review');
            Route::post('/import/{gedcomImport}/confirm', [GedcomImportController::class, 'confirm'])->name('import.confirm');
        });
    });
});

// Public shared album route
Route::get('/albums/shared/{token}', [AlbumController::class, 'showShared'])->name('albums.shared');
Route::get('/albums/shared/{token}/media/{media}/file/{conversion?}', [AlbumController::class, 'sharedFile'])->name('albums.shared.file');

// Health check endpoint for Docker
Route::get('/health', function () {
    return response()->json([
        'status' => 'healthy',
        'app' => config('app.name'),
        'version' => '1.0.0',
    ]);
});
