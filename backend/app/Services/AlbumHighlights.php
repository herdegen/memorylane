<?php

namespace App\Services;

use App\Models\Album;
use App\Models\Person;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Mise en scène d'un album selon son thème : ce que la page montre en tête,
 * avant toutes les photos (les mariés, les témoins, les photos de groupe,
 * les personnes d'un fonds d'archives, ses décennies…).
 *
 * Tout se calcule à partir de ce qui existe : personnes identifiées sur les
 * photos (media_person), visages détectés, moment de vie lié à l'album et
 * rôles posés dans l'album (album_roles). Seules les personnes que le
 * visiteur peut voir apparaissent.
 *
 * Renvoie une liste de sections (clé, type, titre, médias…) que la page
 * affiche dans l'ordre, ou null quand le thème n'a rien de particulier.
 */
class AlbumHighlights
{
    /** Rôles possibles, par thème (le premier est le rôle « principal »). */
    public const ROLES_BY_THEME = [
        'mariage' => ['marie', 'temoin'],
        'bapteme' => ['baptise', 'parrain', 'marraine'],
        'anniversaire' => ['fete'],
        'reunion' => ['fete'],
        'fete' => ['fete'],
    ];

    public const ROLES = ['marie', 'temoin', 'baptise', 'parrain', 'marraine', 'fete'];

    /** Types de moments de vie qui désignent la personne principale. */
    private const EVENT_TYPES = [
        'mariage' => ['mariage', 'mariage_religieux', 'fiancailles'],
        'bapteme' => ['bapteme', 'communion', 'confirmation'],
        'anniversaire' => ['anniversaire'],
    ];

    /** Visages reconnus à partir desquels une photo est « de groupe ». */
    private const GROUP_MIN_FACES = ['mariage' => 8, 'default' => 6];

    private Album $album;

    private User $user;

    /** @var Collection<int,string> médias de l'album, dans l'ordre de la page */
    private Collection $ids;

    /** @var array<string,array<int,string>> personnes identifiées par média */
    private array $tags = [];

    /** @var array<string,int> visages détectés par média */
    private array $faces = [];

    /** @var Collection<string,Person> personnes visibles, par id */
    private Collection $people;

    public function for(Album $album, User $user): ?array
    {
        $theme = $album->theme;
        if (! in_array($theme, ['mariage', 'bapteme', 'anniversaire', 'reunion', 'fete', 'archives', 'voyage'], true)) {
            return null;
        }
        $this->album = $album;
        $this->user = $user;
        $this->ids = $album->media->pluck('id')->values();
        if ($this->ids->isEmpty()) {
            return null;
        }
        $this->load();

        $sections = match ($theme) {
            'mariage' => $this->wedding(),
            'bapteme' => $this->baptism(),
            'anniversaire', 'reunion', 'fete' => $this->celebration($theme),
            'archives' => $this->archives(),
            'voyage' => $this->trip(),
        };
        $sections = array_values(array_filter($sections));
        if ($sections === []) {
            return null;
        }

        return [
            'theme' => $theme,
            'roles' => self::ROLES_BY_THEME[$theme] ?? [],
            'sections' => $sections,
            // Pour choisir les rôles : les personnes des photos, les plus vues d'abord.
            'candidates' => $this->canEdit() ? $this->candidates() : [],
        ];
    }

    // ---- Thèmes ---------------------------------------------------------------

    private function wedding(): array
    {
        [$spouses, $fromEvent] = $this->principals('marie', 'mariage');
        $witnesses = $this->role('temoin');
        $sid = $spouses->pluck('id')->all();

        $couple = $sid ? $this->mediaWhere(fn ($m) => $this->hasAll($m, $sid) && $this->faceCount($m) <= 3) : [];
        $withWitness = $witnesses->isNotEmpty() && $sid
            ? $this->mediaWhere(fn ($m) => $this->hasAny($m, $witnesses->pluck('id')->all()) && $this->hasAny($m, $sid))
            : [];

        return [
            $spouses->isEmpty()
                ? $this->roleSection('principal', 'marie', __('Les mariés'), collect(), [], __('Qui sont les mariés ? Leurs photos à deux apparaîtront ici.'))
                : $this->roleSection('principal', 'marie', $this->names($spouses), $spouses, $couple, null,
                    $fromEvent ? __('Les mariés viennent du moment de vie lié à l’album') : null),
            $this->roleSection('witnesses', 'temoin', __('Les témoins'), $witnesses, $withWitness,
                __('Choisissez-les parmi les invités : leurs photos avec les mariés apparaîtront ici.')),
            $this->groupSection('mariage'),
            $this->peopleSection('guests', __('Les invités'), __('les plus photographiés'), exclude: [...$sid, ...$witnesses->pluck('id')->all()]),
        ];
    }

    private function baptism(): array
    {
        [$children, $fromEvent] = $this->principals('baptise', 'bapteme');
        $godparents = $this->role('parrain')->concat($this->role('marraine'))->unique('id')->values();
        $cid = $children->pluck('id')->all();

        return [
            $children->isEmpty()
                ? $this->roleSection('principal', 'baptise', __('Le baptisé'), collect(), [], __('Qui est à l’honneur ? Ses photos apparaîtront ici.'))
                : $this->roleSection('principal', 'baptise', $this->names($children), $children,
                    $this->mediaWhere(fn ($m) => $this->hasAny($m, $cid)), null,
                    $fromEvent ? __('Vient du moment de vie lié à l’album') : null),
            $this->roleSection('godparents', 'parrain', __('Parrain et marraine'), $godparents,
                $godparents->isNotEmpty() && $cid ? $this->mediaWhere(fn ($m) => $this->hasAny($m, $godparents->pluck('id')->all()) && $this->hasAny($m, $cid)) : [],
                __('Ajoutez le parrain et la marraine : leurs photos avec l’enfant apparaîtront ici.')),
            $this->groupSection('bapteme'),
            $this->peopleSection('guests', __('Les invités'), __('les plus photographiés'), exclude: [...$cid, ...$godparents->pluck('id')->all()]),
        ];
    }

    private function celebration(string $theme): array
    {
        [$honored] = $this->principals('fete', $theme === 'anniversaire' ? 'anniversaire' : null);
        $hid = $honored->pluck('id')->all();
        $title = $honored->isEmpty() ? __('À l’honneur') : $this->names($honored, withAge: $theme === 'anniversaire');
        $principal = $this->roleSection('principal', 'fete', $title, $honored,
            $hid ? $this->mediaWhere(fn ($m) => $this->hasAny($m, $hid)) : [],
            $theme === 'anniversaire' ? __('Qui fête-t-on ? Ses photos apparaîtront ici.') : __('Quelqu’un à mettre à l’honneur ? Ses photos apparaîtront ici.'));
        // Réunion ou fête sans personne à l'honneur : pas de bloc vide en tête,
        // juste la proposition (pour qui peut modifier l'album).
        if ($theme !== 'anniversaire' && $honored->isEmpty() && $principal) {
            $principal['compact'] = true;
        }

        return [
            $principal,
            $this->groupSection($theme),
            $theme === 'reunion'
                ? $this->peopleSection('present', __('Qui était là'), null, exclude: [], limit: 40)
                : $this->peopleSection('guests', __('Les invités'), __('les plus photographiés'), exclude: $hid),
        ];
    }

    private function archives(): array
    {
        $dated = $this->album->media->filter(fn ($m) => $m->taken_at !== null);
        $decades = $dated->groupBy(fn ($m) => intdiv((int) $m->taken_at->format('Y'), 10) * 10)
            ->sortKeys()
            ->map(fn ($g, $d) => ['key' => (int) $d, 'title' => self::decadeLabel((int) $d), 'subtitle' => null, 'ids' => $g->pluck('id')->values()->all()])
            ->values()->all();
        $undated = $this->album->media->filter(fn ($m) => $m->taken_at === null)->pluck('id')->values()->all();

        return [
            $this->peopleSection('people', __('Les personnes'), __('touchez un nom pour ne voir que ses photos'), exclude: [], limit: 16, lifeDates: true),
            $decades ? ['key' => 'decades', 'type' => 'rows', 'title' => __('Au fil du temps'),
                'subtitle' => trans_choice(':count photo datée|:count photos datées', $dated->count()), 'rows' => $decades] : null,
            $undated ? ['key' => 'undated', 'type' => 'undated', 'title' => trans_choice(':count photo sans date|:count photos sans date', count($undated)),
                'text' => __('Une date, même approximative (« vers 1950 »), et la photo rejoint sa décennie.'), 'ids' => $undated] : null,
        ];
    }

    /**
     * Voyage : les photos jour par jour (Jour 1, Jour 2…), avec le lieu du
     * jour. Le séjour est tout l'album s'il tient en trois semaines, sinon la
     * plus grosse suite de jours photographiés ; les photos égarées hors de
     * cette période vont dans « Autres dates » (jamais de « Jour 3245 »).
     */
    private function trip(): array
    {
        $dated = $this->album->media->filter(fn ($m) => $m->taken_at !== null)
            ->sortBy(fn ($m) => [$m->taken_at->timestamp, $m->clip_start ?? 0]);
        $byDay = $dated->groupBy(fn ($m) => $m->taken_at->format('Y-m-d'))->sortKeys();
        if ($byDay->count() < 2) {
            return [];
        }

        // Tout l'album tient en trois semaines : c'est le séjour (des jours
        // sans photo au milieu ne le coupent pas). Sinon, suites de jours à
        // moins de 3 jours d'écart.
        $days = $byDay->keys()->all();
        $segments = [[]];
        $prev = null;
        $short = Carbon::parse($days[0])->diffInDays(Carbon::parse(end($days))) < 21;
        foreach ($days as $day) {
            if ($prev && ! $short && Carbon::parse($prev)->diffInDays(Carbon::parse($day)) > 2) {
                $segments[] = [];
            }
            $segments[array_key_last($segments)][] = $day;
            $prev = $day;
        }
        $stay = collect($segments)->sortByDesc(fn ($days) => collect($days)->sum(fn ($d) => $byDay[$d]->count()))->first();
        $start = Carbon::parse($stay[0]);
        $length = (int) $start->diffInDays(Carbon::parse(end($stay))) + 1;
        if (count($stay) < 2 || $length > 31) {
            return [];
        }

        $coords = \App\Models\MediaMetadata::whereIn('media_id', $dated->pluck('id'))
            ->whereNotNull('latitude')->whereNotNull('longitude')
            ->get(['media_id', 'latitude', 'longitude'])->keyBy('media_id');
        $geocoder = app(GeocodeService::class);
        $missing = [];
        $rows = [];
        foreach ($stay as $day) {
            $media = $byDay[$day];
            // Lieux du jour : les cellules d'~1 km les plus photographiées.
            $names = $media->filter(fn ($m) => isset($coords[$m->id]))
                ->groupBy(fn ($m) => round((float) $coords[$m->id]->latitude, 2).','.round((float) $coords[$m->id]->longitude, 2))
                ->sortByDesc(fn ($g) => $g->count())->take(3)
                ->map(function ($g) use ($coords, $geocoder, &$missing) {
                    $c = $coords[$g->first()->id];
                    [$lat, $lng] = [(float) $c->latitude, (float) $c->longitude];
                    if (! $geocoder->isPlaceNameResolved($lat, $lng)) {
                        $missing[] = [$lat, $lng];
                    }

                    return $geocoder->cachedPlaceName($lat, $lng);
                })
                // Noms en alphabet latin seulement (« Chiraz » oui, un district
                // rural écrit en persan non).
                ->filter()->map(fn ($n) => trim(explode(',', $n)[0]))
                ->filter(fn ($n) => preg_match('/\p{Latin}/u', $n))
                ->unique()->take(2)->values();
            $date = Carbon::parse($day)->locale(app()->getLocale())->translatedFormat('l j F');
            $rows[] = [
                'key' => $day,
                'title' => __('Jour :n', ['n' => (int) $start->diffInDays(Carbon::parse($day)) + 1]),
                'subtitle' => $names->isEmpty() ? $date : $date.' · '.$names->implode(', '),
                'ids' => $media->pluck('id')->values()->all(),
            ];
        }
        \App\Jobs\ResolvePlaceNames::dispatchOnce(array_slice($missing, 0, 60));

        $others = $dated->reject(fn ($m) => in_array($m->taken_at->format('Y-m-d'), $stay, true))->pluck('id')->values()->all();
        if ($others) {
            $rows[] = ['key' => 'others', 'title' => __('Autres dates'), 'subtitle' => null, 'ids' => $others];
        }
        $undated = $this->album->media->filter(fn ($m) => $m->taken_at === null)->pluck('id')->values()->all();
        if ($undated) {
            $rows[] = ['key' => 'undated', 'title' => __('Sans date'), 'subtitle' => null, 'ids' => $undated];
        }

        return [[
            'key' => 'days', 'type' => 'rows', 'title' => __('Jour par jour'),
            'subtitle' => trans_choice(':count jour|:count jours', $length), 'rows' => $rows,
        ]];
    }

    /** « Années 50 », « Années 2000 » ; en anglais « 1950s ». */
    private static function decadeLabel(int $decade): string
    {
        return app()->getLocale() === 'en'
            ? "{$decade}s"
            : __('Années :decade', ['decade' => $decade < 2000 ? substr((string) $decade, 2) : (string) $decade]);
    }

    // ---- Briques --------------------------------------------------------------

    private function load(): void
    {
        $visible = Person::visibleTo($this->user)->select('people.id');
        foreach (DB::table('media_person')->whereIn('media_id', $this->ids)->whereIn('person_id', $visible)->get(['media_id', 'person_id']) as $row) {
            $this->tags[$row->media_id][] = $row->person_id;
        }
        $this->faces = DB::table('detected_faces')->whereIn('media_id', $this->ids)
            ->groupBy('media_id')->selectRaw('media_id, count(*) as n')->pluck('n', 'media_id')
            ->map(fn ($n) => (int) $n)->all();

        $roleIds = DB::table('album_roles')->where('album_id', $this->album->id)->pluck('person_id');
        $tagged = collect($this->tags)->flatten()->unique();
        $this->people = Person::visibleTo($this->user)
            ->whereIn('people.id', $tagged->merge($roleIds)->unique()->values())
            ->withCount(['detectedFaces as matched_faces_count' => fn ($q) => $q->where('status', 'matched')])
            ->get()->keyBy('id');
    }

    /** Personnes ayant ce rôle dans l'album (visibles seulement). */
    private function role(string $role): Collection
    {
        return DB::table('album_roles')->where('album_id', $this->album->id)->where('role', $role)
            ->orderBy('id')->pluck('person_id')
            ->map(fn ($id) => $this->people[$id] ?? null)->filter()->values();
    }

    /**
     * Personne(s) principale(s) : le rôle posé dans l'album, sinon le moment
     * de vie lié (et, pour un mariage, le conjoint le plus présent).
     *
     * @return array{0: Collection, 1: bool} [personnes, déduites du moment de vie]
     */
    private function principals(string $role, ?string $eventTheme): array
    {
        $set = $this->role($role);
        if ($set->isNotEmpty() || ! $eventTheme) {
            return [$set, false];
        }
        $event = DB::table('life_events')->where('album_id', $this->album->id)
            ->whereIn('type', self::EVENT_TYPES[$eventTheme])->orderBy('event_date')->first();
        $person = $event ? Person::visibleTo($this->user)->find($event->person_id) : null;
        if (! $person) {
            return [collect(), false];
        }
        $out = collect([$this->people[$person->id] ?? $person]);
        if ($eventTheme === 'mariage') {
            $spouse = $person->spousesAsFirst->merge($person->spousesAsSecond)
                ->filter(fn ($p) => isset($this->people[$p->id]))
                ->sortByDesc(fn ($p) => $this->photoCount($p->id))->first();
            if ($spouse) {
                $out->push($this->people[$spouse->id]);
            }
        }

        return [$out, true];
    }

    private function roleSection(string $key, string $role, string $title, Collection $people, array $ids, ?string $empty, ?string $why = null): ?array
    {
        if ($people->isEmpty() && ! $this->canEdit()) {
            return null;
        }

        return [
            'key' => $key, 'type' => 'role', 'role' => $role, 'title' => $title, 'why' => $why,
            'people' => $people->map(fn ($p) => $this->card($p))->values()->all(),
            'ids' => $ids, 'empty' => $empty,
        ];
    }

    private function groupSection(string $theme): ?array
    {
        $min = self::GROUP_MIN_FACES[$theme] ?? self::GROUP_MIN_FACES['default'];
        $ids = collect($this->mediaWhere(fn ($m) => $this->faceCount($m) >= $min))
            ->sortByDesc(fn ($m) => $this->faceCount($m))->values()->all();
        if (count($ids) < 2) {
            return null;
        }

        return [
            'key' => 'group', 'type' => 'tiles', 'title' => __('Photos de groupe'),
            'why' => __('Photos où l’on voit au moins :count visages', ['count' => $min]), 'ids' => $ids,
        ];
    }

    private function peopleSection(string $key, string $title, ?string $subtitle, array $exclude, int $limit = 12, bool $lifeDates = false): ?array
    {
        $counts = [];
        foreach ($this->tags as $media => $people) {
            foreach (array_unique($people) as $p) {
                if (! in_array($p, $exclude, true)) {
                    $counts[$p][] = $media;
                }
            }
        }
        uasort($counts, fn ($a, $b) => count($b) <=> count($a));
        $list = collect($counts)->take($limit)
            ->map(fn ($ids, $id) => isset($this->people[$id]) ? $this->card($this->people[$id], $lifeDates) + ['ids' => $this->ordered($ids)] : null)
            ->filter()->values();
        if ($list->count() < 2) {
            return null;
        }

        return ['key' => $key, 'type' => 'people', 'title' => $title, 'subtitle' => $subtitle, 'people' => $list->all()];
    }

    private function candidates(): array
    {
        $counts = [];
        foreach ($this->tags as $people) {
            foreach (array_unique($people) as $p) {
                $counts[$p] = ($counts[$p] ?? 0) + 1;
            }
        }
        arsort($counts);

        return collect($counts)->keys()->take(60)
            ->map(fn ($id) => isset($this->people[$id]) ? $this->card($this->people[$id]) : null)
            ->filter()->values()->all();
    }

    private function card(Person $p, bool $lifeDates = false): array
    {
        $years = null;
        if ($lifeDates) {
            $b = $p->birth_date?->format('Y');
            $d = $p->death_date?->format('Y');
            $years = $b ? trim("{$b} – ".($d ?? '')) : ($d ? "† {$d}" : null);
        }

        return [
            'id' => $p->id,
            'name' => $p->name,
            'first_name' => $p->first_name ?: explode(' ', (string) $p->name)[0],
            'avatar_url' => $p->avatarUrl(),
            'avatar_position' => $p->avatar_position ?? null,
            'years' => $years,
            'count' => $this->photoCount($p->id),
        ];
    }

    /** « Matthieu & Marion », « Loïc, Matthieu & Paul », « Paul · 30 ans ». */
    private function names(Collection $people, bool $withAge = false): string
    {
        $first = $people->map(fn ($p) => $p->first_name ?: explode(' ', (string) $p->name)[0])->values();
        $label = $first->count() > 1 ? $first->slice(0, -1)->implode(', ').' & '.$first->last() : (string) $first->first();
        if ($withAge && $people->count() === 1) {
            $born = $people->first()->birth_date;
            $when = $this->album->date ?? $this->album->media->pluck('taken_at')->filter()->sort()->values()->get(intdiv($this->album->media->pluck('taken_at')->filter()->count(), 2));
            if ($born && $when && $when->greaterThan($born)) {
                $label .= ' · '.trans_choice(':count an|:count ans', (int) $born->diffInYears($when));
            }
        }

        return $label;
    }

    private function canEdit(): bool
    {
        return $this->user->can('update', $this->album);
    }

    private function mediaWhere(callable $test): array
    {
        return $this->ids->filter($test)->values()->all();
    }

    private function ordered(array $ids): array
    {
        $set = array_flip($ids);

        return $this->ids->filter(fn ($id) => isset($set[$id]))->values()->all();
    }

    private function hasAll(string $media, array $people): bool
    {
        return ! array_diff($people, $this->tags[$media] ?? []);
    }

    private function hasAny(string $media, array $people): bool
    {
        return (bool) array_intersect($people, $this->tags[$media] ?? []);
    }

    /** Visages sur la photo : détectés, sinon personnes identifiées. */
    private function faceCount(string $media): int
    {
        return max($this->faces[$media] ?? 0, count(array_unique($this->tags[$media] ?? [])));
    }

    private function photoCount(string $person): int
    {
        return collect($this->tags)->filter(fn ($p) => in_array($person, $p, true))->count();
    }
}
