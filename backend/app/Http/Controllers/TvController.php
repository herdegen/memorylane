<?php

namespace App\Http\Controllers;

use App\Models\Album;
use App\Models\Media;
use App\Models\Person;
use App\Models\TvDevice;
use App\Models\User;
use App\Models\VoiceNote;
use App\Services\AlbumDiscovery;
use App\Services\GenealogyService;
use App\Services\MediaService;
use App\Services\QuestService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
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

    /** Relations chargées pour un média de visionneuse. */
    private const ITEM_RELATIONS = ['conversions', 'people', 'albums', 'voiceNotes.person', 'voiceNotes.user'];

    /** Albums au plus dans une rangée. */
    private const ROW_LIMIT = 24;

    public function __construct(
        protected MediaService $mediaService,
        protected AlbumDiscovery $discovery,
        protected QuestService $quests,
        protected GenealogyService $genealogy,
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
            ->with(self::ITEM_RELATIONS)
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

    /** Photos d'un proche (onglet Proches, carte « Personne du jour », recherche). */
    public function person(Request $request, string $person)
    {
        $user = $request->user();
        $person = Person::visibleTo($user)->with('avatar')->withMatchedFacesCount()->findOrFail($person);

        $media = $person->media()->inContext($user)->whereIn('type', ['photo', 'video'])
            ->with(self::ITEM_RELATIONS)
            ->orderByRaw('taken_at IS NULL')->orderBy('taken_at')
            ->limit(self::ITEMS_LIMIT)->get();

        $relation = null;
        if ($user->person_id && $user->person_id !== $person->id
            && $path = $this->genealogy->pathBetween($user->person_id, $person->id)) {
            $relation = GenealogyService::relationLabel($path['edges'], $person->gender);
        }

        $family = $this->familyMode($user);

        return Inertia::render('Tv/Person', [
            ...$this->frame($request, 'people'),
            'person' => [
                'id' => $person->id,
                'name' => $person->name,
                'relation' => $relation ? ucfirst($relation) : ($person->id === $user->person_id ? 'Vous' : null),
                'life' => $this->lifeLabel($person),
                'count' => $this->countLabel($media),
                'avatar' => $this->avatarUrl($person),
            ],
            'items' => $media->map(fn (Media $m) => $this->item($m, $user))->values(),
            // Frise et arbre : mode famille seulement (comme sur la fiche du site).
            'showLife' => $family,
            'inTree' => $family && $person->in_family_directory,
            'back' => url()->previous() !== url()->current() && str_contains(url()->previous(), '/tv/ecran/')
                ? url()->previous() : route('tv.screen.people'),
        ]);
    }

    /** « Ce jour-là » : les photos prises un même jour les années passées. */
    public function memories(Request $request)
    {
        $user = $request->user();
        $today = today();
        $media = $this->onThisDayQuery($user, $today)
            ->with(self::ITEM_RELATIONS)
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

    /** Photos : une carte par année (rangées par décennie), plus vidéos et souvenirs racontés. */
    public function photos(Request $request)
    {
        $user = $request->user();
        $base = fn () => Media::inContext($user)->whereIn('type', ['photo', 'video']);

        $years = $base()->whereNotNull('taken_at')->toBase()
            ->selectRaw('extract(year from taken_at)::int as y, count(*) as n')
            ->groupBy('y')->orderByDesc('y')->get();

        // Une couverture par année, la même toute la journée (photos avec des proches d'abord).
        $coverIds = $base()->where('type', 'photo')->whereNotNull('taken_at')->toBase()
            ->selectRaw('distinct on (extract(year from taken_at)) media.id, extract(year from taken_at)::int as y')
            ->orderByRaw('extract(year from taken_at), (exists (select 1 from detected_faces df where df.media_id = media.id and df.person_id is not null)) desc, md5(media.id::text || ?)', [today()->toDateString()])
            ->pluck('id', 'y');
        $covers = Media::with('conversions')->whereIn('id', $coverIds->values())->get()->keyBy('id');
        $cover = fn ($id) => isset($covers[$id]) ? $this->mediaService->thumbnailUrl($covers[$id], ['medium', 'small', 'thumbnail']) : null;

        $decades = $years->groupBy(fn ($r) => intdiv($r->y, 10) * 10)
            ->map(fn ($rows, $decade) => [
                'key' => 'decade-'.$decade,
                'title' => 'Les années '.$decade,
                'tiles' => $rows->map(fn ($r) => [
                    'key' => 'y'.$r->y,
                    'title' => (string) $r->y,
                    'meta' => $this->countLabelOf((int) $r->n),
                    'cover' => $cover($coverIds[$r->y] ?? null),
                    'href' => route('tv.screen.photoSet', ['set' => $r->y]),
                ])->values(),
            ])->values();

        $special = collect();
        $videos = $base()->where('type', 'video')->count();
        if ($videos) {
            $first = $base()->where('type', 'video')->with('conversions')->latest('taken_at')->first();
            $special->push(['key' => 'videos', 'title' => 'Vidéos', 'meta' => $videos.' '.($videos > 1 ? 'vidéos' : 'vidéo'),
                'cover' => $first ? $this->mediaService->thumbnailUrl($first, ['medium', 'small', 'thumbnail']) : null,
                'href' => route('tv.screen.photoSet', ['set' => 'videos'])]);
        }
        $voices = $base()->has('voiceNotes')->count();
        if ($voices) {
            $first = $base()->has('voiceNotes')->with('conversions')->latest('updated_at')->first();
            $special->push(['key' => 'voices', 'title' => 'Souvenirs racontés', 'meta' => $voices.' '.($voices > 1 ? 'photos racontées' : 'photo racontée'),
                'cover' => $first ? $this->mediaService->thumbnailUrl($first, ['medium', 'small', 'thumbnail']) : null,
                'href' => route('tv.screen.photoSet', ['set' => 'souvenirs-racontes'])]);
        }
        $undated = $base()->whereNull('taken_at')->count();
        if ($undated) {
            $special->push(['key' => 'undated', 'title' => 'Sans date', 'meta' => $this->countLabelOf($undated), 'cover' => null,
                'href' => route('tv.screen.photoSet', ['set' => 'sans-date'])]);
        }

        return Inertia::render('Tv/Photos', [
            ...$this->frame($request, 'photos'),
            'rows' => $special->isNotEmpty()
                ? collect([['key' => 'special', 'title' => 'À regarder', 'tiles' => $special->values()]])->concat($decades)->values()
                : $decades,
        ]);
    }

    /** Une année, les vidéos, les souvenirs racontés ou les photos sans date. */
    public function photoSet(Request $request, string $set)
    {
        $user = $request->user();
        $query = Media::inContext($user)->whereIn('type', ['photo', 'video'])->with(self::ITEM_RELATIONS);

        [$title, $query] = match (true) {
            ctype_digit($set) && strlen($set) === 4 => [$set, $query->whereRaw('extract(year from taken_at) = ?', [(int) $set])->orderBy('taken_at')],
            $set === 'videos' => ['Vidéos', $query->where('type', 'video')->orderByRaw('taken_at IS NULL')->orderByDesc('taken_at')],
            $set === 'souvenirs-racontes' => ['Souvenirs racontés', $query->has('voiceNotes')->orderByRaw('taken_at IS NULL')->orderBy('taken_at')],
            $set === 'sans-date' => ['Sans date', $query->whereNull('taken_at')->orderBy('created_at')],
            default => abort(404),
        };
        $media = $query->limit(self::ITEMS_LIMIT)->get();

        return Inertia::render('Tv/Collection', [
            ...$this->frame($request, 'photos'),
            'title' => $title,
            'subtitle' => $this->countLabel($media),
            'items' => $media->map(fn (Media $m) => $this->item($m, $user))->values(),
            'gameAlbumId' => null,
            'back' => route('tv.screen.photos'),
        ]);
    }

    /** Proches : les fiches du cercle qui ont des photos, les plus proches d'abord. */
    public function people(Request $request)
    {
        $user = $request->user();
        $people = Person::inContext($user)
            ->with('avatar')->withMatchedFacesCount()
            ->withCount(['media as tv_photos' => fn ($q) => $q->inContext($user)->whereIn('type', ['photo', 'video'])])
            ->get()
            ->filter(fn (Person $p) => $p->tv_photos > 0);

        $distance = $user->person_id ? $this->genealogy->proximity($user->person_id)['distance'] : [];
        $paths = $user->person_id ? $this->genealogy->pathsFrom($user->person_id, 4) : [];

        $card = function (Person $p) use ($paths, $user) {
            $relation = isset($paths[$p->id]) && $p->id !== $user->person_id
                ? GenealogyService::relationLabel($paths[$p->id], $p->gender) : null;

            return [
                'id' => $p->id,
                'name' => $p->name,
                'sub' => $p->id === $user->person_id ? 'Vous' : ($relation ? ucfirst($relation) : ($this->lifeLabel($p) ?? $this->countLabelOf($p->tv_photos))),
                'avatar_url' => $this->avatarUrl($p),
            ];
        };
        $sorted = $people->sortBy([
            fn ($a, $b) => ($distance[$a->id] ?? PHP_INT_MAX) <=> ($distance[$b->id] ?? PHP_INT_MAX),
            fn ($a, $b) => $b->tv_photos <=> $a->tv_photos,
            fn ($a, $b) => strcmp($a->name, $b->name),
        ]);

        $rows = collect([
            ['key' => 'close', 'title' => 'Les plus proches', 'people' => $sorted->filter(fn ($p) => ($distance[$p->id] ?? 99) <= 2)],
            ['key' => 'family', 'title' => 'La famille', 'people' => $sorted->filter(fn ($p) => isset($distance[$p->id]) && $distance[$p->id] > 2)],
            ['key' => 'others', 'title' => $distance ? 'Et aussi' : 'Proches', 'people' => $sorted->filter(fn ($p) => ! isset($distance[$p->id]))
                ->sortByDesc('tv_photos')],
        ])->filter(fn ($r) => $r['people']->isNotEmpty())
            ->map(fn ($r) => ['key' => $r['key'], 'title' => $r['title'], 'people' => $r['people']->map($card)->values()])
            ->values();

        return Inertia::render('Tv/People', [
            ...$this->frame($request, 'people'),
            'rows' => $rows,
        ]);
    }

    public function search(Request $request)
    {
        return Inertia::render('Tv/Search', [
            ...$this->frame($request, 'search'),
            'voice' => true,
        ]);
    }

    /** Résultats de recherche (en tapant) : proches, albums, années. Fautes et accents tolérés. */
    public function searchResults(Request $request): JsonResponse
    {
        $q = trim((string) $request->validate(['q' => 'required|string|min:2|max:100'])['q']);
        $user = $request->user();
        $norm = fn (string $s) => preg_replace('/\s+/', ' ', trim(Str::lower(Str::ascii($s))));
        $nq = $norm($q);

        // Proches : Meilisearch (fautes tolérées), cercle de la TV seulement,
        // et seulement ceux qu'on voit sur des photos (rien à regarder sinon).
        $people = $this->safe(fn () => Person::search($q)
            ->query(fn ($b) => $b->inContext($user)
                ->whereHas('media', fn ($m) => $m->inContext($user)->whereIn('type', ['photo', 'video']))
                ->with('avatar')->withMatchedFacesCount())
            ->take(100)->get());
        $people = $people->sortBy(fn (Person $p) => str_starts_with($norm($p->name), $nq) || str_contains(' '.$norm($p->name), ' '.$nq) ? 0 : 1)
            ->take(12)
            ->map(fn (Person $p) => ['id' => $p->id, 'name' => $p->name, 'sub' => $this->lifeLabel($p), 'avatar_url' => $this->avatarUrl($p)])
            ->values();

        // Albums du cercle : nom qui contient les mots, ou trouvé par Meilisearch.
        $albums = $this->albumsInContext($user);
        $scout = $this->safe(fn () => Album::search($q)->take(40)->keys())->flip();
        $tokens = array_filter(explode(' ', $nq));
        $albums = $albums->filter(fn (Album $a) => isset($scout[$a->id])
                || collect($tokens)->every(fn ($t) => str_contains($norm($a->name), $t)))
            ->take(12)->map(fn ($a) => $this->albumCard($a))->values();

        // Une année tapée (« 1998 ») : ses photos.
        $years = [];
        if (preg_match('/^(18|19|20)\d{2}$/', $q)) {
            $n = Media::inContext($user)->whereIn('type', ['photo', 'video'])->whereRaw('extract(year from taken_at) = ?', [(int) $q])->count();
            if ($n) {
                $years[] = ['key' => 'y'.$q, 'title' => $q, 'meta' => $this->countLabelOf($n), 'cover' => null, 'href' => route('tv.screen.photoSet', ['set' => $q])];
            }
        }

        return response()->json(['people' => $people, 'albums' => $albums, 'years' => $years]);
    }

    /** Cadre photo : un lot de photos du cercle tirées au sort (proches identifiés d'abord). */
    public function frameMode(Request $request): JsonResponse
    {
        $user = $request->user();
        $media = Media::inContext($user)->where('type', 'photo')
            ->with(['conversions', 'albums', 'people'])
            ->orderByRaw('(exists (select 1 from detected_faces df where df.media_id = media.id and df.person_id is not null)) desc')
            ->inRandomOrder()
            ->limit(400)->get()
            ->shuffle()->take(150);

        return response()->json(['items' => $media->map(fn (Media $m) => [
            'id' => $m->id,
            'src' => $this->mediaService->thumbnailUrl($m, ['large', 'medium']),
            'title' => SimpleModeController::caption($m),
            'when' => $m->taken_at ? self::dateLabel($m->taken_at) : null,
        ])->values()]);
    }

    // ---- Arbre de la famille -----------------------------------------------

    /** Arbre centré sur une personne (par défaut : soi), parcouru à la télécommande. */
    public function tree(Request $request, ?string $person = null)
    {
        $user = $request->user();
        abort_unless($this->familyMode($user), 404);

        $start = $person
            ? $this->familyQuery($user)->whereKey($person)->value('id')
            : ($user->person_id && $this->familyQuery($user)->whereKey($user->person_id)->exists()
                ? $user->person_id
                // Sans fiche à soi : la personne la plus photographiée de la famille.
                : $this->familyQuery($user)->withCount('media')->orderByDesc('media_count')->value('id'));
        abort_unless($start, 404);

        return Inertia::render('Tv/Tree', [
            ...$this->frame($request, 'tree'),
            'start' => $this->treeFamily($user, $start),
        ]);
    }

    /** Une personne, ses parents, son ou ses conjoints, ses frères et sœurs, ses enfants. */
    public function treeData(Request $request, string $person): JsonResponse
    {
        $user = $request->user();
        abort_unless($this->familyMode($user), 404);

        return response()->json($this->treeFamily($user, $person));
    }

    private function familyQuery(User $user)
    {
        return Person::visibleTo($user)->where('in_family_directory', true);
    }

    private function treeFamily(User $user, string $personId): array
    {
        $q = fn () => $this->familyQuery($user)->with('avatar')->withMatchedFacesCount()
            ->withCount(['media as tv_photos' => fn ($m) => $m->inContext($user)->whereIn('type', ['photo', 'video'])]);
        $focus = $q()->findOrFail($personId);
        $byBirth = fn ($query) => $query->orderByRaw('birth_date IS NULL')->orderBy('birth_date')->orderBy('name');

        $parents = $byBirth($q()->whereIn('id', array_filter([$focus->father_id, $focus->mother_id])))->get()
            ->sortBy(fn ($p) => $p->id === $focus->father_id ? 0 : 1)->values();
        $children = $byBirth($q()->where(fn ($w) => $w->where('father_id', $focus->id)->orWhere('mother_id', $focus->id)))->get();
        $siblings = ($focus->father_id || $focus->mother_id)
            ? $byBirth($q()->where('id', '!=', $focus->id)->where(fn ($w) => $w
                ->when($focus->father_id, fn ($x) => $x->orWhere('father_id', $focus->father_id))
                ->when($focus->mother_id, fn ($x) => $x->orWhere('mother_id', $focus->mother_id))))->get()
            : collect();

        $unions = DB::table('person_relationships')
            ->where('person1_id', $focus->id)->orWhere('person2_id', $focus->id)
            ->get()->keyBy(fn ($r) => $r->person1_id === $focus->id ? $r->person2_id : $r->person1_id);
        $spouses = $q()->whereIn('id', $unions->keys())->get()
            ->sortBy(fn ($p) => [$unions[$p->id]->end_date ? 1 : 0, $unions[$p->id]->start_date ?? '9999'])->values();

        // Lien de parenté avec soi, pour chaque carte.
        $paths = $user->person_id ? $this->genealogy->pathsFrom($user->person_id, 8) : [];
        // Qui a encore de la famille au-delà (flèches sur les cartes).
        $ids = collect([$focus])->concat($parents)->concat($children)->concat($siblings)->concat($spouses)->pluck('id');
        $withChildren = Person::whereIn('father_id', $ids)->pluck('father_id')
            ->concat(Person::whereIn('mother_id', $ids)->pluck('mother_id'))->flip();

        $node = function (Person $p) use ($paths, $user, $withChildren) {
            $relation = $p->id === $user->person_id ? 'Vous'
                : (isset($paths[$p->id]) ? GenealogyService::relationLabel($paths[$p->id], $p->gender) : null);

            return [
                'id' => $p->id,
                'name' => $p->name,
                'first_name' => SimpleModeController::shortName($p),
                'life' => $this->lifeLabel($p),
                'relation' => $relation ? ucfirst($relation) : null,
                'avatar_url' => $this->avatarUrl($p),
                'gender' => $p->gender,
                'deceased' => $p->death_date !== null,
                'photos' => (int) $p->tv_photos,
                'has_parents' => (bool) ($p->father_id || $p->mother_id),
                'has_children' => isset($withChildren[$p->id]),
            ];
        };

        return [
            'focus' => $node($focus),
            'parents' => $parents->map($node)->values(),
            'spouses' => $spouses->map(fn ($p) => $node($p) + ['union' => $this->unionLabel($unions[$p->id], $p)])->values(),
            'siblings' => $siblings->map($node)->values(),
            'children' => $children->map($node)->values(),
        ];
    }

    /** « Mariés en 1955 », « En couple », « Séparés en 1990 ». */
    private function unionLabel(object $union, Person $spouse): string
    {
        $married = $union->type !== 'partner';
        if ($union->end_date) {
            return ($married ? 'Divorcés' : 'Séparés').' en '.substr($union->end_date, 0, 4);
        }
        if ($union->start_date) {
            return ($married ? 'Mariés' : 'En couple depuis').' '.($married ? 'en ' : '').substr($union->start_date, 0, 4);
        }

        return $married ? 'Mariés' : 'En couple';
    }

    private function familyMode(User $user): bool
    {
        return $user->mode() !== \App\Models\Household::KIND_FRIENDS && $user->canSeeFamilyDirectory();
    }

    // ---- Carte ---------------------------------------------------------------

    /** Lieux de grille (~10 km) : arrondi des coordonnées à 0,1°. */
    private const MAP_CELL = 1;

    /** Lieux montrés au plus sur la carte. */
    private const MAP_PLACES = 120;

    /** Les endroits où la famille a pris des photos, du plus photographié au moins. */
    public function map(Request $request)
    {
        $user = $request->user();
        $ids = Media::inContext($user)->whereIn('type', ['photo', 'video'])->select('media.id');
        $cell = 'round(mm.latitude::numeric, '.self::MAP_CELL.')';
        $cellLng = 'round(mm.longitude::numeric, '.self::MAP_CELL.')';

        $places = DB::table('media_metadata as mm')->join('media as m', 'm.id', '=', 'mm.media_id')
            ->whereIn('mm.media_id', $ids)->whereNotNull('mm.latitude')->whereNotNull('mm.longitude')
            ->selectRaw("{$cell} as clat, {$cellLng} as clng, avg(mm.latitude) as lat, avg(mm.longitude) as lng, count(*) as n,
                min(m.taken_at) as first_at, max(m.taken_at) as last_at")
            ->groupByRaw('1, 2')->orderByDesc('n')->limit(self::MAP_PLACES)->get();

        // Aperçus : 6 photos par lieu (des proches identifiés d'abord).
        $previews = collect(DB::select("select clat, clng, media_id from (
                select {$cell} as clat, {$cellLng} as clng, mm.media_id, row_number() over (
                    partition by {$cell}, {$cellLng}
                    order by exists (select 1 from detected_faces df where df.media_id = mm.media_id and df.person_id is not null) desc,
                             md5(mm.media_id::text || ?)
                ) as rn
                from media_metadata mm join media m on m.id = mm.media_id
                where m.type = 'photo' and m.deleted_at is null and mm.latitude is not null and mm.longitude is not null
                  and mm.media_id in (".$ids->toSql().")
            ) t where rn <= 6", [today()->toDateString(), ...$ids->getBindings()]));
        $media = Media::with('conversions')->whereIn('id', $previews->pluck('media_id')->unique())->get()->keyBy('id');
        $previewsByCell = $previews->groupBy(fn ($r) => $r->clat.'_'.$r->clng);

        // Noms : géocodage inverse (cache), sinon l'album le plus présent ; les manquants en arrière-plan.
        $albumNames = DB::table('media_metadata as mm')->join('album_media as am', 'am.media_id', '=', 'mm.media_id')
            ->join('albums as a', 'a.id', '=', 'am.album_id')
            ->whereIn('mm.media_id', $ids)->whereNotNull('mm.latitude')->whereNull('a.deleted_at')
            ->selectRaw("{$cell} as clat, {$cellLng} as clng, a.name, count(*) as n")
            ->groupByRaw('1, 2, 3')->orderByDesc('n')->get()
            ->groupBy(fn ($r) => $r->clat.'_'.$r->clng)->map(fn ($g) => $g->first()->name);

        $geocoder = app(\App\Services\GeocodeService::class);
        $missing = [];
        $payload = $places->map(function ($p) use ($geocoder, &$missing, $previewsByCell, $media, $albumNames) {
            $key = $p->clat.'_'.$p->clng;
            $name = $geocoder->cachedPlaceName((float) $p->lat, (float) $p->lng);
            if (! $name && ! $geocoder->isPlaceNameResolved((float) $p->lat, (float) $p->lng)) {
                $missing[] = [round((float) $p->lat, 4), round((float) $p->lng, 4)];
            }
            $from = $p->first_at ? substr($p->first_at, 0, 4) : null;
            $to = $p->last_at ? substr($p->last_at, 0, 4) : null;

            return [
                'key' => $key,
                'name' => $name ?? ($albumNames[$key] ?? 'Lieu sans nom'),
                'album' => $name ? ($albumNames[$key] ?? null) : null,
                'lat' => (float) $p->lat,
                'lng' => (float) $p->lng,
                'count' => (int) $p->n,
                'years' => $from ? ($from === $to ? $from : "{$from} → {$to}") : null,
                'previews' => ($previewsByCell[$key] ?? collect())->map(fn ($r) => isset($media[$r->media_id])
                    ? $this->mediaService->thumbnailUrl($media[$r->media_id], ['medium', 'small', 'thumbnail']) : null)
                    ->filter()->values(),
            ];
        })->values();
        \App\Jobs\ResolvePlaceNames::dispatchOnce($missing);

        return Inertia::render('Tv/Map', [
            ...$this->frame($request, 'map'),
            'places' => $payload,
        ]);
    }

    /** Les photos d'un lieu de la carte. */
    public function place(Request $request, string $place)
    {
        abort_unless(preg_match('/^(-?\d{1,3}(?:\.\d)?)_(-?\d{1,3}(?:\.\d)?)$/', $place, $m), 404);
        $user = $request->user();

        $media = Media::inContext($user)->whereIn('type', ['photo', 'video'])
            ->whereIn('media.id', DB::table('media_metadata as mm')->select('mm.media_id')
                ->whereRaw('round(mm.latitude::numeric, '.self::MAP_CELL.') = ?', [(float) $m[1]])
                ->whereRaw('round(mm.longitude::numeric, '.self::MAP_CELL.') = ?', [(float) $m[2]]))
            ->with(self::ITEM_RELATIONS)
            ->orderByRaw('taken_at IS NULL')->orderBy('taken_at')
            ->limit(self::ITEMS_LIMIT)->get();
        abort_if($media->isEmpty(), 404);

        $name = $request->query('nom') ?: 'Ce lieu';

        return Inertia::render('Tv/Collection', [
            ...$this->frame($request, 'map'),
            'title' => mb_substr($name, 0, 80),
            'subtitle' => $this->countLabel($media),
            'items' => $media->map(fn (Media $m) => $this->item($m, $user))->values(),
            'gameAlbumId' => null,
            'back' => route('tv.screen.map'),
        ]);
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
            // Onglet Arbre : cercle familial seulement.
            'family' => $this->familyMode($user),
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

    private function countLabelOf(int $n): string
    {
        return $n.' '.($n > 1 ? 'photos' : 'photo');
    }

    /** « 1932 – 2004 », « né en 1987 ». */
    private function lifeLabel(Person $person): ?string
    {
        if (! $person->birth_date) {
            return $person->death_date ? '† '.$person->death_date->year : null;
        }

        return $person->death_date
            ? $person->birth_date->year.' – '.$person->death_date->year
            : ($person->gender === 'F' ? 'Née en ' : 'Né en ').$person->birth_date->year;
    }

    private function safe(callable $search): Collection
    {
        try {
            return collect($search());
        } catch (\Throwable $e) {
            report($e);

            return collect();
        }
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
