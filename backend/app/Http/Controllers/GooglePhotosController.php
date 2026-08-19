<?php

namespace App\Http\Controllers;

use App\Jobs\ImportGooglePhotosItems;
use App\Models\Media;
use App\Services\MediaService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Inertia\Inertia;

/**
 * Import depuis Google Photos via le Picker API.
 *
 * Flux : OAuth (photospicker.mediaitems.readonly) → création d'une session
 * Picker → l'utilisateur choisit ses photos dans l'interface Google (il peut
 * y chercher par personne, lieu, date) → polling jusqu'à la fin de la
 * sélection → import en arrière-plan avec rattachement optionnel à une
 * personne et/ou un album MemoryLane.
 */
class GooglePhotosController extends Controller
{
    protected const OAUTH_AUTHORIZE_URL = 'https://accounts.google.com/o/oauth2/v2/auth';
    protected const OAUTH_TOKEN_URL = 'https://oauth2.googleapis.com/token';
    protected const PICKER_BASE_URL = 'https://photospicker.googleapis.com/v1';
    protected const SCOPE = 'https://www.googleapis.com/auth/photospicker.mediaitems.readonly';

    /**
     * Page d'import : état de connexion + listes personne/album pour le
     * rattachement.
     */
    public function index(Request $request)
    {
        return Inertia::render('Media/GooglePhotosImport', [
            'isConnected' => $request->session()->has('google_photos.access_token'),
            'pickerSession' => $request->session()->get('google_photos.picker_session'),
            'people' => \App\Models\Person::orderBy('name')->get(['id', 'name']),
            'albums' => \App\Models\Album::where('user_id', $request->user()->id)
                ->orderBy('name')
                ->get(['id', 'name']),
        ]);
    }

    /**
     * Redirige vers le consentement Google.
     */
    public function connect(Request $request)
    {
        $state = Str::random(40);
        $request->session()->put('google_photos.oauth_state', $state);

        $params = http_build_query([
            'client_id' => config('services.google_photos.client_id'),
            'redirect_uri' => config('services.google_photos.redirect'),
            'response_type' => 'code',
            'scope' => self::SCOPE,
            'state' => $state,
            'prompt' => 'select_account',
        ]);

        return redirect()->away(self::OAUTH_AUTHORIZE_URL . '?' . $params);
    }

    /**
     * Callback OAuth : échange le code contre un access token (en session).
     */
    public function callback(Request $request)
    {
        if (
            ! $request->filled('code')
            || $request->input('state') !== $request->session()->pull('google_photos.oauth_state')
        ) {
            return redirect()->route('google-photos.index')
                ->withErrors(['google' => 'La connexion à Google Photos a échoué. Réessayez.']);
        }

        $response = Http::asForm()->post(self::OAUTH_TOKEN_URL, [
            'client_id' => config('services.google_photos.client_id'),
            'client_secret' => config('services.google_photos.client_secret'),
            'code' => $request->input('code'),
            'grant_type' => 'authorization_code',
            'redirect_uri' => config('services.google_photos.redirect'),
        ]);

        if (! $response->successful() || ! $response->json('access_token')) {
            return redirect()->route('google-photos.index')
                ->withErrors(['google' => 'Google a refusé la connexion. Réessayez.']);
        }

        $request->session()->put('google_photos.access_token', $response->json('access_token'));

        return redirect()->route('google-photos.index');
    }

    /**
     * Crée une session Picker et renvoie l'URL de sélection Google.
     */
    public function createSession(Request $request)
    {
        $token = $request->session()->get('google_photos.access_token');
        abort_unless($token, 409, 'Google Photos n\'est pas connecté.');

        // Google exige un objet JSON explicite ({}), un corps vide est rejeté
        $response = Http::withToken($token)
            ->withBody('{}', 'application/json')
            ->post(self::PICKER_BASE_URL . '/sessions');

        if (! $response->successful()) {
            return $this->googleErrorResponse($request, $response, 'createSession');
        }

        $request->session()->put('google_photos.picker_session', [
            'id' => $response->json('id'),
            'pickerUri' => $response->json('pickerUri'),
        ]);

        return response()->json([
            'id' => $response->json('id'),
            'pickerUri' => $response->json('pickerUri'),
        ]);
    }

    /**
     * Polling : la sélection est-elle terminée côté Google ?
     */
    public function sessionStatus(Request $request)
    {
        $token = $request->session()->get('google_photos.access_token');
        $session = $request->session()->get('google_photos.picker_session');
        abort_unless($token && $session, 409, 'Aucune session de sélection en cours.');

        $response = Http::withToken($token)->get(self::PICKER_BASE_URL . '/sessions/' . $session['id']);

        if (! $response->successful()) {
            return $this->googleErrorResponse($request, $response, 'sessionStatus');
        }

        return response()->json([
            'mediaItemsSet' => (bool) $response->json('mediaItemsSet', false),
        ]);
    }

    /**
     * Abandonne la session de sélection en cours (l'utilisateur a fermé
     * l'onglet Picker ou renonce) : on l'oublie côté serveur et, best effort,
     * côté Google. Débloque l'état « En attente de votre sélection ».
     */
    public function cancelSession(Request $request)
    {
        $token = $request->session()->get('google_photos.access_token');
        $session = $request->session()->pull('google_photos.picker_session');

        if ($token && $session) {
            try {
                Http::withToken($token)->delete(self::PICKER_BASE_URL . '/sessions/' . $session['id']);
            } catch (\Throwable $e) {
                // best effort : la session Google expirera d'elle-même
            }
        }

        return response()->json(['cancelled' => true]);
    }

    /**
     * Traduit une erreur de l'API Google en réponse exploitable : token
     * expiré (401) vs API désactivée / autre (avec le vrai message Google,
     * loggé pour diagnostic).
     */
    protected function googleErrorResponse(Request $request, $response, string $context)
    {
        Log::warning("GooglePhotos: {$context} failed", [
            'status' => $response->status(),
            'body' => $response->json() ?? $response->body(),
        ]);

        if ($response->status() === 401) {
            // Token réellement expiré ou révoqué : on repart de la connexion
            $request->session()->forget('google_photos.access_token');
            return response()->json(['error' => 'session_expired'], 401);
        }

        return response()->json([
            'error' => 'google_error',
            'google_status' => $response->status(),
            'message' => $response->json('error.message') ?? 'Erreur Google inconnue.',
        ], 502);
    }

    /**
     * Lance l'import en arrière-plan, avec rattachement optionnel.
     */
    public function import(Request $request)
    {
        $validated = $request->validate([
            'person_id' => ['nullable', 'uuid', 'exists:people,id'],
            // L'album cible doit appartenir à l'importateur (sinon on écrirait
            // dans l'album d'un autre compte).
            'album_id' => [
                'nullable',
                'uuid',
                \Illuminate\Validation\Rule::exists('albums', 'id')->where('user_id', auth()->id()),
            ],
            'new_album_name' => ['nullable', 'string', 'max:255'],
        ]);

        $token = $request->session()->get('google_photos.access_token');
        $session = $request->session()->get('google_photos.picker_session');
        abort_unless($token && $session, 409, 'Aucune sélection à importer.');

        // Album cible : soit un existant, soit un nouveau créé à la volée.
        $albumId = $validated['album_id'] ?? null;
        if (! $albumId && ! empty($validated['new_album_name'])) {
            $albumId = \App\Models\Album::create([
                'user_id' => auth()->id(),
                'name' => $validated['new_album_name'],
            ])->id;
        }

        // Réinitialise l'état terminal (un nouvel import n'est pas « terminé »).
        Cache::forget(ImportGooglePhotosItems::statusKey(auth()->id()));

        ImportGooglePhotosItems::dispatch(
            userId: auth()->id(),
            accessToken: $token,
            pickerSessionId: $session['id'],
            personId: $validated['person_id'] ?? null,
            albumId: $albumId,
        );

        // La session Picker ne sert plus côté navigateur
        $request->session()->forget('google_photos.picker_session');

        if ($request->wantsJson()) {
            return response()->json([
                'started_at' => now()->toIso8601String(),
                'message' => 'Import lancé.',
            ]);
        }

        return redirect()->route('media.index')->with(
            'success',
            'Import lancé : vos photos Google arrivent dans la galerie d\'ici quelques minutes.'
        );
    }

    /**
     * Suivi de l'import en cours : nombre de médias arrivés depuis `after`
     * (horodatage de lancement) + un aperçu des derniers, pour un affichage
     * progressif côté page d'import.
     */
    public function importedStatus(Request $request, MediaService $mediaService)
    {
        $after = $request->query('after');

        $query = Media::where('user_id', auth()->id());
        if ($after) {
            $query->where('created_at', '>=', $after);
        }

        $count = (clone $query)->count();

        $items = (clone $query)
            ->latest('created_at')
            ->with('conversions')
            ->limit(12)
            ->get()
            ->map(function (Media $m) use ($mediaService) {
                $conv = $m->conversions->firstWhere('conversion_name', 'small')
                    ?? $m->conversions->first();
                $url = $conv
                    ? $mediaService->getSignedUrl($m, $conv->file_path)
                    : $mediaService->getSignedUrl($m);

                return ['id' => $m->id, 'url' => $url, 'name' => $m->original_name];
            });

        $status = Cache::get(ImportGooglePhotosItems::statusKey(auth()->id()));

        return response()->json([
            'count' => $count,
            'items' => $items,
            'finished' => (bool) ($status['finished'] ?? false),
            'failed' => (bool) ($status['failed'] ?? false),
        ]);
    }
}
