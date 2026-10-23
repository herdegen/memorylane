<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ReportsImportProgress;
use App\Jobs\ImportGoogleDriveFiles;
use App\Models\Album;
use App\Models\Person;
use App\Rules\VisiblePerson;
use App\Services\GoogleOAuth;
use App\Services\MediaService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * Import depuis Google Drive, sans passer par l'ordinateur.
 *
 * Flux : OAuth (drive.file : MemoryLane ne voit QUE les fichiers que
 * l'utilisateur choisit) → sélecteur Google Picker dans la page (dossiers,
 * « Partagés avec moi », recherche) → le serveur télécharge les fichiers
 * choisis directement depuis Google, en arrière-plan, avec rattachement
 * optionnel à une personne et/ou un album.
 */
class GoogleDriveController extends Controller
{
    use ReportsImportProgress;

    protected const SCOPE = 'https://www.googleapis.com/auth/drive.file';

    /**
     * Page d'import : configuration du sélecteur + listes personne/album.
     */
    public function index(Request $request)
    {
        $apiKey = config('services.google_drive.api_key');

        return Inertia::render('Media/GoogleDriveImport', [
            'isConfigured' => (bool) $apiKey,
            'isConnected' => $request->session()->has('google_drive.token'),
            'picker' => [
                'apiKey' => $apiKey,
                // Numéro du projet Google Cloud = préfixe de l'ID client OAuth.
                'appId' => config('services.google_drive.app_id')
                    ?: Str::before((string) config('services.google_photos.client_id'), '-'),
            ],
            'people' => Person::inContext($request->user())->orderBy('name')->get(['id', 'name']),
            'albums' => Album::where('user_id', $request->user()->id)
                ->orderBy('name')
                ->get(['id', 'name']),
        ]);
    }

    /**
     * Redirige vers le consentement Google (avec refresh token : un gros
     * import peut durer plus que l'heure de validité du jeton).
     */
    public function connect(Request $request, GoogleOAuth $oauth)
    {
        $state = Str::random(40);
        $request->session()->put('google_drive.oauth_state', $state);

        return redirect()->away($oauth->authorizeUrl(self::SCOPE, $state, offline: true));
    }

    /**
     * Callback OAuth (appelé via l'URI de retour commune, cf.
     * GooglePhotosController::callback).
     */
    public function callback(Request $request, GoogleOAuth $oauth)
    {
        if (
            ! $request->filled('code')
            || $request->input('state') !== $request->session()->pull('google_drive.oauth_state')
        ) {
            return redirect()->route('google-drive.index')
                ->withErrors(['google' => __('La connexion à Google Drive a échoué. Réessayez.')]);
        }

        $token = $oauth->exchangeCode($request->input('code'));

        if (! $token) {
            return redirect()->route('google-drive.index')
                ->withErrors(['google' => __('Google a refusé la connexion. Réessayez.')]);
        }

        $request->session()->put('google_drive.token', $token);

        return redirect()->route('google-drive.index');
    }

    /**
     * Jeton d'accès frais pour ouvrir le sélecteur dans le navigateur
     * (demandé à l'ouverture plutôt que posé dans la page).
     */
    public function token(Request $request, GoogleOAuth $oauth)
    {
        $token = $this->freshToken($request, $oauth);

        if (! $token) {
            return response()->json(['error' => 'session_expired'], 401);
        }

        return response()->json(['access_token' => $token['access_token']]);
    }

    /**
     * Lance l'import en arrière-plan, avec rattachement optionnel.
     */
    public function import(Request $request, GoogleOAuth $oauth)
    {
        $validated = $request->validate([
            'file_ids' => ['required', 'array', 'min:1', 'max:2000'],
            'file_ids.*' => ['required', 'string', 'max:200', 'regex:/^[A-Za-z0-9_-]+$/'],
            'person_id' => ['nullable', 'uuid', new VisiblePerson],
            // L'album cible doit appartenir à l'importateur.
            'album_id' => [
                'nullable',
                'uuid',
                Rule::exists('albums', 'id')->where('user_id', auth()->id()),
            ],
            'new_album_name' => ['nullable', 'string', 'max:255'],
            // Partage avec un cercle dont on est membre (comme à l'envoi).
            'share_household_id' => ['nullable', 'uuid'],
        ]);
        $householdId = $validated['share_household_id'] ?? null;
        abort_if($householdId && ! $request->user()->circles()->contains('id', $householdId), 403);

        $token = $this->freshToken($request, $oauth);
        if (! $token) {
            return response()->json(['error' => 'session_expired'], 401);
        }

        $albumId = $validated['album_id'] ?? null;
        if (! $albumId && ! empty($validated['new_album_name'])) {
            $albumId = Album::create([
                'user_id' => auth()->id(),
                'name' => $validated['new_album_name'],
            ])->id;
        }

        // Réinitialise l'état terminal (un nouvel import n'est pas « terminé »).
        Cache::forget(ImportGoogleDriveFiles::statusKey(auth()->id()));

        ImportGoogleDriveFiles::dispatch(
            userId: auth()->id(),
            accessToken: $token['access_token'],
            refreshToken: $token['refresh_token'] ?? null,
            fileIds: array_values(array_unique($validated['file_ids'])),
            personId: $validated['person_id'] ?? null,
            albumId: $albumId,
            householdId: $householdId,
        );

        return response()->json([
            'started_at' => now()->toIso8601String(),
            'message' => __('Import lancé.'),
        ]);
    }

    /**
     * Suivi de l'import en cours (affichage progressif côté page d'import).
     */
    public function importedStatus(Request $request, MediaService $mediaService)
    {
        return $this->importProgress($request, $mediaService, ImportGoogleDriveFiles::statusKey(auth()->id()));
    }

    /**
     * Jeton de session encore valable quelques minutes, renouvelé au besoin ;
     * null (et session nettoyée) si la connexion Google est perdue.
     */
    protected function freshToken(Request $request, GoogleOAuth $oauth): ?array
    {
        $token = $request->session()->get('google_drive.token');
        if (! $token) {
            return null;
        }

        if (($token['expires_at'] ?? 0) > now()->addMinutes(5)->timestamp) {
            return $token;
        }

        $refreshed = ! empty($token['refresh_token']) ? $oauth->refresh($token['refresh_token']) : null;
        if (! $refreshed) {
            $request->session()->forget('google_drive.token');

            return null;
        }

        $request->session()->put('google_drive.token', $refreshed);

        return $refreshed;
    }
}
