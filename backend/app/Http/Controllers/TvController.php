<?php

namespace App\Http\Controllers;

use App\Models\Album;
use App\Models\Media;
use App\Models\Person;
use App\Models\TvDevice;
use App\Models\User;
use App\Models\VoiceNote;
use App\Services\AlbumDiscovery;
use App\Services\MediaService;
use App\Services\QuestService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * Interface télévision (/tv/ecran) : « vue à 3 mètres », télécommande. Rien
 * à saisir, rien à envoyer : regarder les albums et les souvenirs, lancer un
 * diaporama, jouer à « Qui est-ce ? ». Mêmes droits de lecture qu'un membre
 * (cercle de la TV) ; les restrictions de la session sont dans RestrictTv.
 */
class TvController extends Controller
{
    /** Médias chargés au plus pour une visionneuse (album, proche, souvenirs). */
    private const ITEMS_LIMIT = 1500;

    /** Albums au plus dans une rangée. */
    private const ROW_LIMIT = 24;

    public function __construct(
        protected MediaService $mediaService,
        protected AlbumDiscovery $discovery,
        protected QuestService $quests,
    ) {}

    public function home(Request $request)
    {
        $user = $request->user();
        $albums = $this->albumsInContext($user);
        $sections = $this->discovery->sections($user, $albums);

        return Inertia::render('Tv/Home', [
            ...$this->frame($request, 'home'),
            'hero' => $this->onThisDayHero($user) ?? $this->featuredHero($albums, $sections['featured']),
            'person' => $this->personOfTheDay($user),
            'facesToName' => $this->quests->faceQuestions($user, null, [], 0)['total'],
            'albums' => $albums->sortByDesc('added_at')->take(self::ROW_LIMIT)->map(fn ($a) => $this->albumCard($a))->values(),
        ]);
    }

    public function albums(Request $request)
    {
        $user = $request->user();
        $albums = $this->albumsInContext($user);
        $sections = $this->discovery->sections($user, $albums);
        $byId = $albums->keyBy('id');
        $cards = fn (array $ids) => collect($ids)->map(fn ($id) => $byId[$id] ?? null)->filter()
            ->take(self::ROW_LIMIT)->map(fn ($a) => $this->albumCard($a))->values();

        $rows = collect($sections['rows'])->map(fn ($r) => ['key' => $r['key'], 'title' => $r['title'], 'albums' => $cards($r['ids'])]);
        foreach (array_reverse($sections['decades']) as $d) {
            $rows->push(['key' => 'decade-'.$d['decade'], 'title' => 'Les années '.$d['decade'], 'albums' => $cards($d['ids'])]);
        }
        $rows->push([
            'key' => 'all',
            'title' => 'Tous les albums',
            'albums' => $albums->sortBy(fn ($a) => mb_strtolower($a->name))->map(fn ($a) => $this->albumCard($a))->values(),
        ]);

        $featured = $sections['featured'] && isset($byId[$sections['featured']['id']])
            ? $this->albumCard($byId[$sections['featured']['id']]) + ['reason' => $sections['featured']['reason']]
            : null;

        return Inertia::render('Tv/Albums', [
            ...$this->frame($request, 'albums'),
            'featured' => $featured,
            'rows' => $rows->filter(fn ($r) => $r['albums']->isNotEmpty())->values(),
        ]);
    }

    public function album(Request $request, Album $album)
    {
        Gate::authorize('view', $album);
        $user = $request->user();

        $media = $album->media()->whereIn('type', ['photo', 'video'])
            ->with(['conversions', 'people', 'albums', 'voiceNotes.person', 'voiceNotes.user'])
            ->limit(self::ITEMS_LIMIT)->get();
        $dates = $media->pluck('taken_at')->filter();

        return Inertia::render('Tv/Collection', [
            ...$this->frame($request, 'albums'),
            'title' => $album->name,
            'subtitle' => $this->countLabel($media).($dates->isNotEmpty() ? ' · '.$this->periodLabel($dates->min(), $dates->max()) : ''),
            'items' => $media->map(fn (Media $m) => $this->item($m, $user))->values(),
            'gameAlbumId' => $album->id,
        ]);
    }

    /** Photos d'un proche (carte « Personne du jour »). */
    public function person(Request $request, string $person)
    {
        $user = $request->user();
        $person = Person::visibleTo($user)->findOrFail($person);

        $media = $person->media()->inContext($user)->whereIn('type', ['photo', 'video'])
            ->with(['conversions', 'people', 'albums', 'voiceNotes.person', 'voiceNotes.user'])
            ->orderByRaw('taken_at IS NULL')->orderBy('taken_at')
            ->limit(self::ITEMS_LIMIT)->get();

        return Inertia::render('Tv/Collection', [
            ...$this->frame($request, 'home'),
            'title' => $person->name,
            'subtitle' => $this->countLabel($media),
            'items' => $media->map(fn (Media $m) => $this->item($m, $user))->values(),
            'gameAlbumId' => null,
        ]);
    }

    /** « Ce jour-là » : les photos prises un même jour les années passées. */
    public function memories(Request $request)
    {
        $user = $request->user();
        $today = today();
        $media = $this->onThisDayQuery($user, $today)
            ->with(['conversions', 'people', 'albums', 'voiceNotes.person', 'voiceNotes.user'])
            ->orderByDesc('taken_at')
            ->limit(self::ITEMS_LIMIT)->get();

        return Inertia::render('Tv/Collection', [
            ...$this->frame($request, 'home'),
            'title' => 'Ce jour-là, un '.$today->locale('fr')->translatedFormat('j F'),
            'subtitle' => $this->countLabel($media).' des années passées',
            'items' => $media->map(fn (Media $m) => $this->item($m, $user))->values(),
            'gameAlbumId' => null,
        ]);
    }

    public function game(Request $request)
    {
        $album = null;
        if ($id = $request->query('album')) {
            abort_unless(\Illuminate\Support\Str::isUuid((string) $id), 404);
            $album = Album::accessibleBy($request->user())->findOrFail($id);
        }

        return Inertia::render('Tv/Game', [
            ...$this->frame($request, 'game'),
            'album' => $album ? ['id' => $album->id, 'name' => $album->name] : null,
        ]);
    }

    /** Changer le cercle affiché sur cette TV (sans toucher à celui du téléphone). */
    public function circle(Request $request)
    {
        $validated = $request->validate(['household_id' => 'required|uuid']);
        $user = $request->user();
        abort_unless($user->circles()->contains('id', $validated['household_id']), 403);

        if ($device = $this->device($request)) {
            $device->forceFill(['household_id' => $validated['household_id']])->save();
        } else {
            // Aperçu depuis un navigateur ordinaire : c'est le cercle du compte.
            $user->forceFill(['current_household_id' => $validated['household_id']])->save();
        }

        return redirect()->route('tv.screen.home');
    }

    /** « Délier cette télévision » depuis la TV elle-même. */
    public function unlink(Request $request)
    {
        $device = $this->device($request);
        if (! $device) {
            return redirect()->route('dashboard');
        }

        $device->delete();
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        Cookie::queue(Cookie::forget(TvDevice::COOKIE));

        return redirect()->route('tv.screen');
    }

    // ---- Données communes -------------------------------------------------

    private function device(Request $request): ?TvDevice
    {
        $id = $request->session()->get('tv_device_id');

        return $id ? TvDevice::linked()->whereKey($id)->where('user_id', $request->user()->id)->first() : null;
    }

    /** En-tête de toutes les pages : onglet courant, cercle, compte. */
    private function frame(Request $request, string $tab): array
    {
        $user = $request->user();
        $current = $user->currentCircle();

        return [
            'tab' => $tab,
            'circle' => $current ? ['id' => $current->id, 'name' => $current->name] : null,
            'circles' => $user->circles()->map(fn ($c) => ['id' => $c->id, 'name' => $c->name])->values(),
            'account' => [
                'name' => strtok($user->name, ' '),
                'device' => $this->device($request)?->name,
            ],
        ];
    }

    /** Albums du cercle de la TV, avec couvertures vivantes et dates. */
    private function albumsInContext(User $user): Collection
    {
        $albums = Album::accessibleBy($user)
            ->withCount('media')
            ->with(['coverMedia.conversions', 'households:id'])
            ->orderByDesc('updated_at')
            ->get();

        $inContext = $this->discovery->inContextIds($user, $albums);
        $albums = $albums->filter(fn ($a) => isset($inContext[$a->id]) && $a->media_count > 0)->values();
        $albums->each(fn ($a) => $a->in_context = true);
        $this->discovery->attach($albums);

        return $albums->filter(fn ($a) => count($a->previews) > 0)->values();
    }

    private function albumCard(Album $album): array
    {
        $cover = $album->coverMedia ? $this->mediaService->thumbnailUrl($album->coverMedia, ['medium', 'small', 'thumbnail']) : null;
        $previews = collect($album->previews)->pluck('medium')->all();

        return [
            'id' => $album->id,
            'name' => $album->name,
            'meta' => trim(($album->first_at ? $this->periodLabel(Carbon::parse($album->first_at), Carbon::parse($album->last_at)).' · ' : '')
                .$album->media_count.' '.($album->media_count > 1 ? 'photos' : 'photo')),
            'cover' => $cover ?? ($previews[0] ?? null),
            'previews' => $previews,
            'backgrounds' => collect($album->previews)->pluck('large')->all(),
        ];
    }

    private function onThisDayQuery(User $user, Carbon $today)
    {
        return Media::inContext($user)
            ->whereIn('type', ['photo', 'video'])
            ->whereNotNull('taken_at')
            ->whereRaw('EXTRACT(MONTH FROM taken_at) = ?', [$today->month])
            ->whereRaw('EXTRACT(DAY FROM taken_at) = ?', [$today->day])
            ->whereRaw('EXTRACT(YEAR FROM taken_at) < ?', [$today->year]);
    }

    /** Carte principale de l'accueil : « Ce jour-là » s'il y a des souvenirs du jour. */
    private function onThisDayHero(User $user): ?array
    {
        $today = today();
        $media = $this->onThisDayQuery($user, $today)->where('type', 'photo')
            ->with(['conversions', 'albums'])
            ->withCount(['detectedFaces as named_faces' => fn ($q) => $q->whereNotNull('person_id')])
            ->orderByDesc('taken_at')->limit(200)->get();
        if ($media->isEmpty()) {
            return null;
        }

        // L'année la plus récente, et sa plus belle photo (des proches dessus).
        $year = $media->first()->taken_at->year;
        $ofYear = $media->filter(fn ($m) => $m->taken_at->year === $year);
        $cover = $ofYear->sortByDesc('named_faces')->first();
        $years = $today->year - $year;
        $album = $ofYear->flatMap(fn ($m) => $m->albums)->countBy('name')->sortDesc()->keys()->first();

        return [
            'kicker' => 'Ce jour-là · '.($years === 1 ? 'il y a un an' : "il y a {$years} ans"),
            'title' => $album ?: $today->copy()->year($year)->locale('fr')->translatedFormat('j F Y'),
            'sub' => $today->copy()->year($year)->locale('fr')->translatedFormat('j F Y').' · '.$this->countLabel($media).' de ce jour',
            'image' => $this->mediaService->thumbnailUrl($cover, ['large', 'medium']),
            'href' => route('tv.screen.memories', ['play' => 1]),
            'action' => 'Lancer le diaporama',
        ];
    }

    /** Sinon : l'album à l'honneur de la page Albums. */
    private function featuredHero(Collection $albums, ?array $featured): ?array
    {
        $album = $featured ? $albums->firstWhere('id', $featured['id']) : null;
        if (! $album) {
            return null;
        }
        $card = $this->albumCard($album);

        return [
            'kicker' => $featured['reason'],
            'title' => $album->name,
            'sub' => $card['meta'],
            'image' => $album->coverMedia ? $this->mediaService->thumbnailUrl($album->coverMedia, ['large', 'medium']) : ($card['previews'][0] ?? null),
            'href' => route('tv.screen.album', $album),
            'action' => 'Voir l\'album',
        ];
    }

    /** Un proche du cercle, tiré au sort pour la journée, parmi ceux qui ont des photos. */
    private function personOfTheDay(User $user): ?array
    {
        $ids = Person::inContext($user)
            ->whereHas('media', fn ($q) => $q->inContext($user))
            ->when($user->person_id, fn ($q) => $q->where('people.id', '!=', $user->person_id))
            ->orderBy('id')->pluck('id');
        if ($ids->isEmpty()) {
            return null;
        }

        $person = Person::with('avatar')->withMatchedFacesCount()
            ->find($ids[crc32(today()->toDateString()) % $ids->count()]);
        $count = $person->media()->inContext($user)->count();
        $years = $person->birth_date
            ? $person->birth_date->year.($person->death_date ? ' – '.$person->death_date->year : '')
            : null;

        return [
            'id' => $person->id,
            'name' => $person->name,
            'sub' => trim(($years ? $years.' · ' : '').$count.' '.($count > 1 ? 'photos' : 'photo')),
            'avatar_url' => $this->avatarUrl($person),
        ];
    }

    /** Un média de visionneuse : image ou vidéo, légende, personnes, souvenirs racontés. */
    private function item(Media $media, User $user): array
    {
        $video = $media->type === 'video';

        return [
            'id' => $media->id,
            'type' => $media->type,
            'thumb' => $this->mediaService->thumbnailUrl($media, ['medium', 'small', 'thumbnail']),
            'src' => $video ? $this->mediaService->fileUrl($media) : $this->mediaService->thumbnailUrl($media, ['large', 'medium']),
            'title' => SimpleModeController::caption($media),
            'when' => $media->taken_at ? self::dateLabel($media->taken_at) : null,
            'people' => $media->people
                ->map(fn (Person $p) => $p->id === $user->person_id ? 'Vous' : SimpleModeController::shortName($p))
                ->values()->all(),
            'voice_notes' => $media->voiceNotes->map(fn (VoiceNote $n) => [
                'url' => route('voice-notes.file', $n),
                'by' => $n->person ? SimpleModeController::shortName($n->person) : strtok((string) $n->user?->name, ' '),
                'seconds' => $n->duration_seconds,
            ])->values()->all(),
        ];
    }

    /** « samedi 20 juin 2020 » (ou « 1978 » pour un 1er janvier, souvent approximatif). */
    public static function dateLabel(Carbon $date): string
    {
        if ($date->month === 1 && $date->day === 1) {
            return (string) $date->year;
        }

        return $date->locale('fr')->translatedFormat('l j F Y');
    }

    private function periodLabel(Carbon $from, Carbon $to): string
    {
        if ($from->year !== $to->year) {
            return $from->year.' → '.$to->year;
        }

        return $from->month === $to->month
            ? $from->locale('fr')->translatedFormat('F Y')
            : (string) $from->year;
    }

    private function countLabel(Collection $media): string
    {
        $n = $media->count();
        $videos = $media->where('type', 'video')->count();
        $photos = $n - $videos;
        $parts = [];
        if ($photos) {
            $parts[] = $photos.' '.($photos > 1 ? 'photos' : 'photo');
        }
        if ($videos) {
            $parts[] = $videos.' '.($videos > 1 ? 'vidéos' : 'vidéo');
        }

        return $parts ? implode(' et ', $parts) : 'aucune photo';
    }

    private function avatarUrl(Person $person): ?string
    {
        if ($person->avatar) {
            return route('people.avatarImage', $person);
        }

        return ($person->matched_faces_count ?? 0) > 0 ? url("/people/{$person->id}/face-avatar") : null;
    }
}
