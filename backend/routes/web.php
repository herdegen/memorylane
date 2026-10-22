<?php

use App\Http\Controllers\AlbumController;
use App\Http\Controllers\AlbumParticipantController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\FamilyTreeController;
use App\Http\Controllers\GedcomImportController;
use App\Http\Controllers\NetworkController;
use App\Http\Controllers\FriendshipController;
use App\Http\Controllers\PersonCircleController;
use App\Http\Controllers\PersonLinkController;
use App\Http\Controllers\GoogleDriveController;
use App\Http\Controllers\GooglePhotosController;
use App\Http\Controllers\GuideController;
use App\Http\Controllers\LifeEventController;
use App\Http\Controllers\MediaController;
use App\Http\Controllers\HouseholdController;
use App\Http\Controllers\InvitationController;
use App\Http\Controllers\PersonController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\PushController;
use App\Http\Controllers\QuestController;
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

// Lien d'invitation temporaire (compte invité) : signé, redirige vers une fiche
Route::get('/invite/{user}/{person}', [AuthController::class, 'loginAsGuest'])
    ->middleware('signed')
    ->name('invite.guest');

Route::post('/logout', [AuthController::class, 'logout'])->name('logout')->middleware('auth');

// Langue de l'interface (membre connecté ou visiteur de la page de connexion)
Route::post('/locale', [\App\Http\Controllers\LocaleController::class, 'update'])
    ->middleware('throttle:30,1')
    ->name('locale.update');
// Dictionnaire pour le navigateur (sans extension .js : nginx sert ces
// fichiers-là en statique, sans passer par Laravel).
Route::get('/i18n/{locale}', [\App\Http\Controllers\LocaleController::class, 'script'])
    ->where('locale', '[a-z]{2}')
    ->name('i18n.script');

// Invitation reçue par mail : page « Créer mon accès » (jeton à usage unique)
Route::get('/bienvenue/{token}', [InvitationController::class, 'show'])
    ->middleware('throttle:30,1')
    ->name('invitations.show');
Route::post('/bienvenue/{token}', [InvitationController::class, 'accept'])
    ->middleware('throttle:10,1')
    ->name('invitations.accept');

// Lettre du dimanche : désabonnement depuis le mail (URL signée, sans connexion)
Route::prefix('newsletter')->name('newsletter.')->middleware('signed')->group(function () {
    Route::get('/unsubscribe/{user}', [\App\Http\Controllers\NewsletterController::class, 'show'])->name('unsubscribe');
    Route::post('/unsubscribe/{user}', [\App\Http\Controllers\NewsletterController::class, 'unsubscribe']);
    Route::post('/resubscribe/{user}', [\App\Http\Controllers\NewsletterController::class, 'resubscribe'])->name('resubscribe');
});

// Mode grand-parent : lien tablette sans mot de passe (tablet:create)
Route::get('/tablette/{token}', [AuthController::class, 'loginWithTabletLink'])
    ->middleware('throttle:10,1')
    ->name('tablet.login');

// Application Android TV (APK) : téléchargement libre, adresse courte à taper sur la télévision.
Route::get('/tv/application', [\App\Http\Controllers\TvPairingController::class, 'apk'])->name('tv.apk');
Route::redirect('/apk', '/tv/application');

// Appli télévision : écran d'appairage (TV pas encore reliée) et ses appels.
Route::get('/tv/ecran', [\App\Http\Controllers\TvPairingController::class, 'screen'])->name('tv.screen');
Route::post('/tv/ecran/code', [\App\Http\Controllers\TvPairingController::class, 'start'])
    ->middleware('throttle:20,1')->name('tv.screen.code');
Route::get('/tv/ecran/code', [\App\Http\Controllers\TvPairingController::class, 'status'])
    ->middleware('throttle:60,1')->name('tv.screen.status');

// Protected routes
Route::middleware(['auth', \App\Http\Middleware\RestrictGuest::class, \App\Http\Middleware\RestrictSimpleMode::class, \App\Http\Middleware\RestrictTv::class])->group(function () {
    // Appli télévision (session d'une TV reliée, ou aperçu dans un navigateur)
    Route::prefix('tv/ecran')->name('tv.screen.')->controller(\App\Http\Controllers\TvController::class)->group(function () {
        Route::get('/accueil', 'home')->name('home');
        Route::get('/albums', 'albums')->name('albums');
        Route::get('/albums/{album}', 'album')->name('album');
        Route::get('/photos', 'photos')->name('photos');
        Route::get('/photos/{set}', 'photoSet')->name('photoSet');
        Route::get('/proches', 'people')->name('people');
        Route::get('/proches/{person}', 'person')->name('person');
        Route::get('/recherche', 'search')->name('search');
        Route::get('/recherche/resultats', 'searchResults')->name('search.results');
        Route::get('/cadre', 'frameMode')->name('frame');
        Route::get('/arbre/{person}/famille', 'treeData')->name('tree.data');
        Route::get('/arbre/{person?}', 'tree')->name('tree');
        Route::get('/carte', 'map')->name('map');
        Route::get('/ce-jour-la', 'memories')->name('memories');
        Route::get('/qui-est-ce', 'game')->name('game');
        Route::post('/cercle', 'circle')->name('circle');
        Route::post('/delier', 'unlink')->name('unlink');
    });
    // Téléphone : relier une télévision (code affiché sur la TV), la délier.
    Route::get('/tv', [\App\Http\Controllers\TvPairingController::class, 'link'])->name('tv.link');
    Route::post('/tv', [\App\Http\Controllers\TvPairingController::class, 'confirm'])
        ->middleware('throttle:10,1')->name('tv.link.confirm');
    Route::delete('/tv/{tvDevice}', [\App\Http\Controllers\TvPairingController::class, 'destroy'])->name('tv.link.destroy');

    // Mode grand-parent : interface simplifiée (tablette)
    Route::prefix('simple')->name('simple.')->controller(\App\Http\Controllers\SimpleModeController::class)->group(function () {
        Route::get('/', 'home')->name('home');
        Route::get('/photos', 'photos')->name('photos');
        Route::get('/famille', 'family')->name('family');
        Route::get('/photos/{media}/raconter', 'record')->name('record');
    });

    // Mémos vocaux (« Raconter cette photo »)
    Route::post('/media/{media}/voice-notes', [\App\Http\Controllers\VoiceNoteController::class, 'store'])->name('voice-notes.store');
    Route::get('/voice-notes/{voiceNote}/file', [\App\Http\Controllers\VoiceNoteController::class, 'file'])->name('voice-notes.file');
    Route::delete('/voice-notes/{voiceNote}', [\App\Http\Controllers\VoiceNoteController::class, 'destroy'])->name('voice-notes.destroy');

    // Dashboard
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::post('/dashboard/hide-guide', [DashboardController::class, 'hideGuide'])->name('dashboard.hideGuide');
    // Premiers pas : mini-tuto d'envoi de photos, visite guidée (vus une fois)
    Route::post('/onboarding/{step}', [\App\Http\Controllers\OnboardingController::class, 'store'])
        ->whereIn('step', \App\Http\Controllers\OnboardingController::STEPS)
        ->name('onboarding.done');
    // Guide d'utilisation (bloc « Bien démarrer » + menu avatar) ; les
    // captures illustratives sont servies authentifiées (photos de famille).
    Route::get('/guide', [GuideController::class, 'show'])->name('guide');
    // URL SANS extension : nginx sert les extensions d'images en statique
    // depuis public/ (location ~* \.webp$) et court-circuiterait Laravel.
    Route::get('/guide/images/{name}', [GuideController::class, 'image'])
        ->where('name', '[a-z0-9-]+')->name('guide.image');

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

    // Import Google Drive (Google Picker, scope drive.file) — l'URI de retour
    // OAuth est celle de Google Photos ci-dessus.
    Route::prefix('google-drive')->name('google-drive.')->group(function () {
        Route::get('/', [GoogleDriveController::class, 'index'])->name('index');
        Route::get('/connect', [GoogleDriveController::class, 'connect'])->name('connect');
        Route::post('/token', [GoogleDriveController::class, 'token'])->name('token');
        Route::post('/import', [GoogleDriveController::class, 'import'])->name('import');
        Route::get('/imported', [GoogleDriveController::class, 'importedStatus'])->name('imported');
    });

    // Import Google Takeout (ZIP avec géolocalisation)
    Route::get('/takeout', [TakeoutImportController::class, 'index'])->name('takeout.index');
    Route::post('/takeout', [TakeoutImportController::class, 'store'])->name('takeout.store');

    // Profile routes — page unique (consultation + modification fusionnées)
    // « Quitter MemoryLane » : départ programmé (30 jours), annulable.
    Route::get('/account/departure', [\App\Http\Controllers\AccountDepartureController::class, 'show'])->name('account.departure');
    Route::post('/account/departure', [\App\Http\Controllers\AccountDepartureController::class, 'store'])->name('account.departure.store');
    Route::delete('/account/departure', [\App\Http\Controllers\AccountDepartureController::class, 'destroy'])->name('account.departure.cancel');
    Route::post('/account/export', [\App\Http\Controllers\AccountDepartureController::class, 'export'])->name('account.export');

    Route::prefix('profile')->name('profile.')->group(function () {
        Route::get('/', [ProfileController::class, 'show'])->name('show');
        // Ancienne page « Paramètres » : redirige vers la page unique.
        Route::redirect('/edit', '/profile')->name('edit');
        Route::put('/', [ProfileController::class, 'update'])->name('update');
        Route::put('/password', [ProfileController::class, 'updatePassword'])->name('password');
    });

    // Fête d'anniversaire du jour : vue (ne se rejoue plus aujourd'hui).
    Route::post('/celebrations/birthday-seen', function (\Illuminate\Http\Request $request) {
        app(\App\Services\BirthdayGreeting::class)->markSeen($request->user());

        return response()->noContent();
    })->name('celebrations.birthday-seen');

    Route::put('/profile/newsletter', [\App\Http\Controllers\NewsletterController::class, 'update'])->name('profile.newsletter');

    // Notifications push (Web Push) : abonnement par appareil + préférences
    Route::prefix('push')->name('push.')->group(function () {
        Route::post('/subscriptions', [PushController::class, 'store'])->name('subscriptions.store');
        Route::delete('/subscriptions/{subscription}', [PushController::class, 'destroy'])->name('subscriptions.destroy');
        Route::put('/preferences', [PushController::class, 'updatePreferences'])->name('preferences');
        Route::post('/test', [PushController::class, 'test'])->name('test');
        Route::post('/prompt-dismiss', [PushController::class, 'dismissPrompt'])->name('prompt.dismiss');
    });

    // Media routes
    Route::prefix('media')->name('media.')->group(function () {
        Route::get('/', [MediaController::class, 'index'])->name('index');
        // IDs de tous les médias du filtre courant (« tout sélectionner »).
        // Déclarée avant /{media} pour ne pas être capturée comme un id.
        Route::get('/ids', [MediaController::class, 'ids'])->name('ids');
        // Sélecteur de photos (moments…) : récentes, autour d'une date, personne, album.
        Route::get('/pick', [MediaController::class, 'pick'])->name('pick');
        Route::get('/people', [MediaController::class, 'people'])->name('people');
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
        // Partage foyer : ajoute/retire la sélection du périmètre d'un foyer
        Route::post('/bulk/household', [MediaController::class, 'bulkShareToHousehold'])->name('bulk.household.share');
        Route::post('/bulk/household/remove', [MediaController::class, 'bulkRemoveFromHousehold'])->name('bulk.household.remove');
        Route::get('/{media}', [MediaController::class, 'show'])->name('show');
        Route::put('/{media}', [MediaController::class, 'update'])->name('update');
        Route::delete('/{media}', [MediaController::class, 'destroy'])->name('destroy');
        Route::get('/{media}/download', [MediaController::class, 'download'])->name('download');
        // Porte d'entrée unique des <img>/<video> : auth + policy à chaque
        // chargement, puis 302 vers une présignée S3 très courte.
        Route::get('/{media}/file/{conversion?}', [MediaController::class, 'file'])->name('file');
        // Découpage d'une vidéo en clips (un Media par segment)
        Route::post('/{media}/clips', [MediaController::class, 'storeClips'])->name('storeClips');
        Route::post('/{media}/video-frame', [MediaController::class, 'setVideoFrame'])->name('videoFrame');
        // « Ajouter à la fiche » d'une personne (+ validation par elle).
        Route::post('/{media}/fiches', [\App\Http\Controllers\MediaFicheController::class, 'store'])->name('fiches.store');
        Route::delete('/{media}/fiches/{person}', [\App\Http\Controllers\MediaFicheController::class, 'destroy'])->name('fiches.destroy');
        Route::post('/{media}/fiche-decision', [\App\Http\Controllers\MediaFicheController::class, 'decide'])->name('fiches.decide');
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
        Route::get('/heatmap', [MapController::class, 'heatmap'])->name('heatmap');
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
        Route::post('/{album}/geolocate', [AlbumController::class, 'geolocate'])->name('geolocate');
        Route::post('/{album}/cover', [AlbumController::class, 'setCover'])->name('setCover');
        Route::post('/{album}/pin', [AlbumController::class, 'pin'])->name('pin');
        Route::post('/{album}/hero', [AlbumController::class, 'setHero'])->name('hero');

        // Sharing (lien anonyme)
        Route::post('/{album}/share', [AlbumController::class, 'generateShareToken'])->name('generateShare');
        Route::delete('/{album}/share', [AlbumController::class, 'revokeShareToken'])->name('revokeShare');

        // Partage à des comptes choisis (accès restreint + délégation)
        Route::get('/{album}/access', [AlbumController::class, 'accessList'])->name('access.list');
        Route::get('/{album}/access/candidates', [AlbumController::class, 'grantCandidates'])->name('access.candidates');
        Route::post('/{album}/access', [AlbumController::class, 'grantAccess'])->name('access.grant');
        Route::post('/{album}/access/tagged', [AlbumController::class, 'grantTaggedAccess'])->name('access.grantTagged');
        Route::put('/{album}/share-with-tagged', [AlbumController::class, 'shareWithTagged'])->name('shareWithTagged');
        Route::delete('/{album}/access', [AlbumController::class, 'revokeAccess'])->name('access.revoke');
        // « Qui était du voyage ? »
        Route::get('/{album}/participants', [AlbumParticipantController::class, 'index'])->name('participants.index');
        Route::put('/{album}/participants', [AlbumParticipantController::class, 'update'])->name('participants.update');
        // Partage avec un cercle entier
        Route::get('/{album}/circles', [AlbumController::class, 'circles'])->name('circles.list');
        Route::post('/{album}/circles', [AlbumController::class, 'shareWithCircle'])->name('circles.share');
        Route::delete('/{album}/circles/{household}', [AlbumController::class, 'unshareWithCircle'])->name('circles.unshare');
    });

    // Cercle courant (sélecteur de l'en-tête)
    Route::post('/circles/current', [HouseholdController::class, 'switchCurrent'])->name('circles.current');

    // Cercles (foyers famille / amis) — appartenance, galerie partagée
    Route::prefix('households')->name('households.')->group(function () {
        Route::get('/', [HouseholdController::class, 'index'])->name('index');
        Route::post('/', [HouseholdController::class, 'store'])->name('store');
        Route::get('/{household}', [HouseholdController::class, 'show'])->name('show');
        Route::patch('/{household}', [HouseholdController::class, 'update'])->name('update');
        Route::delete('/{household}', [HouseholdController::class, 'destroy'])->name('destroy');
        Route::get('/{household}/leave', [HouseholdController::class, 'leaveSummary'])->name('leave.summary');
        Route::post('/{household}/leave', [HouseholdController::class, 'leave'])->name('leave');

        Route::get('/{household}/members/candidates', [HouseholdController::class, 'inviteCandidates'])->name('members.candidates');
        Route::post('/{household}/members', [HouseholdController::class, 'invite'])->name('members.invite');
        Route::get('/{household}/members/{user}/removal', [HouseholdController::class, 'removalSummary'])->name('members.removal');
        Route::delete('/{household}/members/{user}', [HouseholdController::class, 'removeMember'])->name('members.remove');

        // Fiches rattachées à un cercle d'amis (administrateur)
        Route::get('/{household}/people/candidates', [HouseholdController::class, 'personCandidates'])->name('people.candidates');
        Route::post('/{household}/people', [HouseholdController::class, 'addPerson'])->name('people.add');
        Route::delete('/{household}/people/{personId}', [HouseholdController::class, 'removePerson'])->name('people.remove');
    });

    // Vision AI routes
    Route::prefix('vision')->name('vision.')->group(function () {
        Route::get('/media/{media}/faces', [VisionController::class, 'faces'])->name('faces');
        Route::get('/media/{media}/image', [VisionController::class, 'image'])->name('image');
        Route::post('/media/{media}/faces', [VisionController::class, 'storeFaces'])->name('storeFaces');
        Route::post('/media/{media}/faces/add', [VisionController::class, 'addFace'])->name('addFace');
        Route::post('/media/{media}/analyze', [VisionController::class, 'analyzeOnServer'])->name('analyzeOnServer');
        Route::post('/batch/analyze', [VisionController::class, 'analyzeBatch'])->name('analyzeBatch');
        Route::post('/faces/{detectedFace}/match', [VisionController::class, 'matchFace'])->name('matchFace');
        Route::post('/faces/{detectedFace}/dismiss', [VisionController::class, 'dismissFace'])->name('dismissFace');
        Route::post('/faces/{detectedFace}/reset', [VisionController::class, 'resetFace'])->name('resetFace');
        Route::get('/faces/{detectedFace}/suggest', [VisionController::class, 'suggest'])->name('suggest');
        Route::get('/faces/{detectedFace}/crop', [VisionController::class, 'faceCrop'])->name('faceCrop');
        Route::post('/faces/{detectedFace}/auto-match', [VisionController::class, 'autoMatch'])->name('autoMatch');
        Route::get('/pending', [VisionController::class, 'pending'])->name('pending');
        Route::post('/no-known-faces', [VisionController::class, 'noKnownFaces'])->name('noKnownFaces');
        Route::get('/batch', fn () => Inertia::render('Vision/Batch'))->name('batch');
    });

    // Gamification « quêtes » : complétion des données depuis le Dashboard
    Route::prefix('quests')->name('quests.')->group(function () {
        Route::get('/next', [QuestController::class, 'next'])->name('next');
        Route::post('/answer', [QuestController::class, 'answer'])->name('answer');
        // Jeu « Qui est-ce ? » plein écran (téléphone), ?album= pour un album
        Route::get('/faces', [QuestController::class, 'faces'])->name('faces');
        Route::get('/faces/next', [QuestController::class, 'facesNext'])->name('faces.next');
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
        // Portrait stocké (versionné par ?v=) : cache navigateur illimité.
        Route::get('/{person}/portrait', [PersonController::class, 'portrait'])->name('portrait');
        Route::put('/{person}', [PersonController::class, 'update'])->name('update');
        Route::delete('/{person}', [PersonController::class, 'destroy'])->name('destroy');
        Route::post('/attach', [PersonController::class, 'attachToMedia'])->name('attach');
        Route::post('/detach', [PersonController::class, 'detachFromMedia'])->name('detach');

        // Family relationships
        Route::post('/{person}/parent', [PersonController::class, 'setParent'])->name('setParent');
        Route::delete('/{person}/parent', [PersonController::class, 'removeParent'])->name('removeParent');
        Route::post('/{person}/set-self', [PersonController::class, 'setSelf'])->name('setSelf');
        // Inviter ce proche à créer son accès (mail + lien /bienvenue)
        Route::post('/{person}/invite', [InvitationController::class, 'store'])->name('invite');
        Route::post('/{person}/spouse', [PersonController::class, 'addSpouse'])->name('addSpouse');
        Route::delete('/{person}/spouse', [PersonController::class, 'removeSpouse'])->name('removeSpouse');
        Route::post('/{person}/child', [PersonController::class, 'addChild'])->name('addChild');

        // Frise de vie + moments
        Route::get('/{person}/timeline', [PersonController::class, 'timeline'])->name('timeline');
        // Lien de parenté avec la fiche « moi » du visiteur (marche dans l'arbre)
        Route::get('/{person}/kinship', [PersonController::class, 'kinship'])->name('kinship');
        // « Vos souvenirs ensemble » (fiche d'un ami)
        Route::get('/{person}/together', [FriendshipController::class, 'show'])->name('together');
        // Cercles d'une fiche : ajouter une fiche existante à un cercle
        Route::get('/{person}/circles', [PersonCircleController::class, 'index'])->name('circles.index');
        Route::post('/{person}/circles', [PersonCircleController::class, 'store'])->name('circles.store');
        Route::delete('/{person}/circles/{household}', [PersonCircleController::class, 'destroy'])->name('circles.destroy');
        // « Comment on s'est connus » (une version par compte)
        Route::get('/{person}/links', [PersonLinkController::class, 'index'])->name('links.index');
        Route::put('/{person}/links', [PersonLinkController::class, 'upsert'])->name('links.upsert');
        Route::delete('/{person}/links', [PersonLinkController::class, 'destroy'])->name('links.destroy');
        Route::post('/{person}/events', [LifeEventController::class, 'store'])->name('events.store');
    });

    // Édition / suppression d'un moment de vie
    Route::put('/life-events/{lifeEvent}', [LifeEventController::class, 'update'])->name('life-events.update');
    Route::delete('/life-events/{lifeEvent}', [LifeEventController::class, 'destroy'])->name('life-events.destroy');

    // Réseau : personnes reliées par les photos (calculé sur les médias visibles)
    Route::prefix('network')->name('network.')->group(function () {
        Route::get('/', [NetworkController::class, 'index'])->name('index');
        Route::get('/data', [NetworkController::class, 'data'])->name('data');
        Route::get('/together', [NetworkController::class, 'together'])->name('together');
    });

    // Aperçu navigateur du mail d'invitation (back-office)
    Route::get('/invitations/{invitation}/apercu', fn (\App\Models\Invitation $invitation) => new \App\Mail\InvitationMail($invitation, $invitation->url() ?? route('login')))
        ->middleware('admin')
        ->name('invitations.preview');

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
// « Se connecter en tant que » : retour au compte admin (bandeau).
Route::post('/impersonate/leave', [\App\Http\Controllers\ImpersonationController::class, 'leave'])
    ->middleware('auth')->name('impersonate.leave');

Route::get('/albums/shared/{token}', [AlbumController::class, 'showShared'])->name('albums.shared');
Route::get('/albums/shared/{token}/media/{media}/file/{conversion?}', [AlbumController::class, 'sharedFile'])->name('albums.shared.file');

