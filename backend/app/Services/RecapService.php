<?php

namespace App\Services;

use App\Models\Media;
use App\Models\Person;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Récaps : courts diaporamas qui racontent le passé de la famille.
 *
 * « Prénom au fil des ans » : une photo par année, de la plus ancienne à la
 * plus récente, avec l'âge ; mis à l'honneur autour de son anniversaire.
 *
 * Tout est calculé pour le visiteur : seules les photos qu'il peut voir, des
 * personnes de son cercle courant ; une photo que la personne a gardée hors
 * de sa fiche (fiche_status « private ») n'y figure pas.
 */
class RecapService
{
    /** Années de photos minimum pour un récap « au fil des ans ». */
    public const MIN_YEARS = 5;

    /** Un anniversaire reste à la une ce nombre de jours (le jour J compris). */
    public const BIRTHDAY_DAYS = 4;

    public function __construct(
        private CelebrationService $celebrations,
        private MediaService $mediaService,
    ) {}

    /**
     * Personnes qui ont un récap « au fil des ans » : au moins 5 années de
     * photos que le visiteur peut voir. Les plus riches d'abord.
     *
     * @return Collection<int, array{person: Person, years: int, photos: int, first: int, last: int}>
     */
    public function throughYearsPeople(User $user): Collection
    {
        // Mis en cache une heure (Accueil) : le décompte parcourt toutes les photos.
        $rows = collect(Cache::remember($this->cacheKey('people', $user), now()->addHour(), fn () => $this->taggedPhotos($user)
            ->whereIn('mp.person_id', Person::inContext($user)->select('people.id'))
            ->groupBy('mp.person_id')
            ->selectRaw('mp.person_id, count(distinct extract(year from m.taken_at)) as years, count(distinct m.id) as photos,
                min(extract(year from m.taken_at)) as first, max(extract(year from m.taken_at)) as last')
            ->havingRaw('count(distinct extract(year from m.taken_at)) >= ?', [self::MIN_YEARS])
            ->get()->map(fn ($r) => (array) $r)->all()))
            ->map(fn ($r) => (object) $r)->keyBy('person_id');
        if ($rows->isEmpty()) {
            return collect();
        }

        return Person::whereIn('id', $rows->keys())
            ->withCount(['detectedFaces as matched_faces_count' => fn ($q) => $q->where('status', 'matched')])
            ->get()
            ->map(fn (Person $p) => [
                'person' => $p,
                'years' => (int) $rows[$p->id]->years,
                'photos' => (int) $rows[$p->id]->photos,
                'first' => (int) $rows[$p->id]->first,
                'last' => (int) $rows[$p->id]->last,
            ])
            ->sortByDesc(fn ($r) => [$r['years'], $r['photos']])
            ->values();
    }

    /**
     * Récaps à la une : les anniversaires des derniers jours (aujourd'hui
     * compris) des personnes qui ont un récap « au fil des ans ».
     *
     * @return list<array<string,mixed>>
     */
    public function featured(User $user, ?CarbonInterface $today = null): array
    {
        $today = ($today ?? now())->copy()->startOfDay();
        $out = [];
        if ($yearCard = $this->featuredYear($user, $today)) {
            $out['year'] = $yearCard;
        }
        $eligible = $this->throughYearsPeople($user)->keyBy(fn ($r) => $r['person']->id);
        if ($eligible->isEmpty()) {
            return array_values($out);
        }

        for ($i = 0; $i < self::BIRTHDAY_DAYS; $i++) {
            $day = $today->copy()->subDays($i);
            foreach ($this->celebrations->birthdaysFor($user, $day) as $b) {
                $id = $b['person']->id;
                if (! isset($eligible[$id]) || isset($out[$id])) {
                    continue;
                }
                $out[$id] = $this->card($eligible[$id], $user, $day->year, $i === 0);
            }
        }

        return array_values($out);
    }

    /** Résumé d'un récap « au fil des ans » (Accueil, page Récaps). */
    public function card(array $row, User $user, ?int $birthdayYear = null, bool $today = false): array
    {
        $p = $row['person'];
        $key = 'years:'.$p->id.($birthdayYear ? ':'.$birthdayYear : '');

        return [
            'key' => $key,
            'type' => 'years',
            'person_id' => $p->id,
            'title' => __(':name au fil des ans', ['name' => $this->firstName($p)]),
            'subtitle' => $birthdayYear
                ? ($today ? __('Anniversaire aujourd’hui') : __('Anniversaire'))
                : $row['first'].' → '.$row['last'],
            'years' => $row['years'],
            'photos' => $row['photos'],
            'avatar_url' => $p->avatarUrl(),
            'avatar_position' => $p->avatar_position ?? null,
            'url' => route('recaps.years', $p),
            'seen' => $birthdayYear ? $this->seen($user, $key) : true,
        ];
    }

    /**
     * Le récap « au fil des ans » d'une personne : une carte d'ouverture, une
     * photo par année (la plus belle de l'année pour elle), une carte de fin.
     *
     * @return array{title: string, slides: list<array>, media: Collection}|null
     */
    public function throughYears(User $user, Person $person, ?CarbonInterface $today = null): ?array
    {
        $today ??= now();
        $rows = $this->taggedPhotos($user)
            ->where('mp.person_id', $person->id)
            ->leftJoin('detected_faces as mine', fn ($j) => $j->on('mine.media_id', '=', 'm.id')
                ->where('mine.person_id', $person->id)->where('mine.status', 'matched'))
            ->selectRaw("m.id, m.taken_at,
                max(coalesce((mine.bounding_box->>'width')::float * (mine.bounding_box->>'height')::float, 0)) as area,
                (select count(*) from detected_faces f where f.media_id = m.id) as faces")
            ->groupBy('m.id', 'm.taken_at')
            ->get();

        // La plus belle photo de chaque année : son visage grand, peu d'autres
        // visages (un portrait plutôt qu'une photo de groupe).
        $best = $rows->groupBy(fn ($r) => substr((string) $r->taken_at, 0, 4))
            ->map(fn ($g) => $g->sortByDesc(fn ($r) => (max((float) $r->area, 0.5)) / (1 + 0.25 * max(0, (int) $r->faces - 1)))->first())
            ->sortKeys();
        if ($best->count() < self::MIN_YEARS) {
            return null;
        }

        $media = Media::with('conversions')->whereIn('id', $best->pluck('id'))->get()->keyBy('id');
        $this->mediaService->hydrateSignedUrls($media->values());

        $name = $this->firstName($person);
        $born = $person->birth_date;
        $first = (int) $best->keys()->first();
        $last = (int) $best->keys()->last();
        $isBirthday = $born && $born->format('m-d') === $today->format('m-d') && ! $person->death_date;

        $slides = [[
            'type' => 'card',
            'card' => [
                'icon' => null,
                'date' => "{$first} → {$last}",
                'title' => __(':name au fil des ans', ['name' => $name]),
                'description' => trans_choice(':count année de photos|:count années de photos', $best->count()),
                'avatarUrl' => $person->avatarUrl(),
            ],
        ]];
        foreach ($best as $year => $r) {
            $takenAt = Carbon::parse($r->taken_at);
            $age = $born && $takenAt->greaterThan($born) ? (int) $born->diffInYears($takenAt) : null;
            $slides[] = [
                'type' => 'photo',
                'media_id' => $r->id,
                'chapter' => (string) $year,
                'label' => $age !== null ? trans_choice(':count an|:count ans', $age) : null,
            ];
        }
        $slides[] = ['type' => 'card', 'card' => $isBirthday
            ? ['icon' => '🎂', 'title' => __('Joyeux anniversaire, :name !', ['name' => $name]),
                'description' => trans_choice(':count an aujourd’hui|:count ans aujourd’hui', (int) $born->diffInYears($today))]
            : ['icon' => '✨', 'title' => $name,
                'description' => trans_choice(':count année de souvenirs|:count années de souvenirs', $last - $first + 1)],
        ];

        return ['title' => __(':name au fil des ans', ['name' => $name]), 'slides' => $slides, 'media' => $media->values()];
    }

    // ---- L'année de la famille --------------------------------------------------

    /** Ajouts minimum pour qu'une année ait son récap. */
    public const YEAR_MIN_MEDIA = 30;

    /** Le récap de l'année est à la une du 15 décembre au 31 janvier. */
    public function featuredYear(User $user, CarbonInterface $today): ?array
    {
        $year = match (true) {
            $today->month === 12 && $today->day >= 15 => $today->year,
            $today->month === 1 => $today->year - 1,
            default => null,
        };
        if (! $year) {
            return null;
        }

        return collect($this->yearCards($user, $today))->firstWhere('year', $year);
    }

    /**
     * Années qui ont un récap : au moins 30 photos ou vidéos AJOUTÉES cette
     * année-là (ce qui est entré dans l'archive, pas ce qui a été photographié).
     *
     * @return list<array<string,mixed>>
     */
    public function yearCards(User $user, ?CarbonInterface $today = null): array
    {
        $today ??= now();
        // Mis en cache six heures : les années et leur couverture changent peu.
        $years = Cache::remember($this->cacheKey('years', $user), now()->addHours(6), function () use ($user) {
            $counts = $this->addedMedia($user)
                ->selectRaw('extract(year from media.created_at)::int as y, count(*) as n')
                ->groupBy('y')->havingRaw('count(*) >= ?', [self::YEAR_MIN_MEDIA])
                ->orderByDesc('y')->pluck('n', 'y');

            return $counts->map(function ($n, $y) use ($user) {
                // L'id seulement : l'adresse de la vignette dépend du domaine
                // de la requête, elle se calcule à chaque fois.
                $cover = $this->addedMedia($user, (int) $y)->where('media.type', 'photo')
                    ->withCount(['detectedFaces as matched' => fn ($q) => $q->where('status', 'matched')])
                    ->orderByDesc('matched')->value('media.id');

                return ['n' => (int) $n, 'cover' => $cover];
            })->all();
        });

        $covers = Media::with('conversions')->whereIn('id', collect($years)->pluck('cover')->filter())->get()->keyBy('id');

        return collect($years)->map(function ($info, $y) use ($user, $today, $covers) {
            $n = $info['n'];
            $key = "year:{$y}";
            $ongoing = (int) $y === $today->year && ! ($today->month === 12 && $today->day >= 15);

            return [
                'key' => $key,
                'type' => 'year',
                'year' => (int) $y,
                'title' => __('L’année :year', ['year' => $y]),
                'subtitle' => $ongoing ? __('en cours') : trans_choice(':count ajout|:count ajouts', (int) $n),
                'avatar_url' => isset($covers[$info['cover']]) ? $this->mediaService->thumbnailUrl($covers[$info['cover']]) : null,
                'avatar_position' => null,
                'url' => route('recaps.year', ['year' => $y]),
                'seen' => $this->seen($user, $key),
            ];
        })->values()->all();
    }

    /**
     * « L'année 2026 » : ce qui est entré dans l'archive cette année-là —
     * les chiffres, les plus vieilles photos redécouvertes, les voyages, un
     * moment par décennie, les plus présents, et merci aux contributeurs.
     */
    public function year(User $user, int $year): ?array
    {
        $added = $this->addedMedia($user, $year);
        $total = (clone $added)->count();
        if ($total < self::YEAR_MIN_MEDIA) {
            return null;
        }

        $photos = (clone $added)->where('media.type', 'photo')->whereNotNull('media.taken_at')
            ->withCount(['detectedFaces as matched' => fn ($q) => $q->where('status', 'matched')])
            ->get(['media.id', 'media.taken_at', 'media.user_id']);
        $ids = $photos->pluck('id');
        $albums = \App\Models\Album::accessibleBy($user)->whereYear('albums.created_at', $year)->count();
        $faces = DB::table('detected_faces')->whereIn('media_id', (clone $added)->select('media.id'))
            ->where('status', 'matched')->count();
        $people = Person::inContext($user)->whereYear('people.created_at', $year)->count();
        $contributors = \App\Models\User::whereIn('id', (clone $added)->select('media.user_id'))->pluck('name')
            ->map(fn ($n) => explode(' ', (string) $n)[0])->sort()->values();

        $picked = collect();
        $slides = [['type' => 'card', 'card' => [
            'icon' => '📖', 'date' => (string) $year,
            'title' => __('L’année :year dans la mémoire familiale', ['year' => $year]),
            'description' => implode("\n", array_filter([
                trans_choice(':count photo ou vidéo ajoutée|:count photos et vidéos ajoutées', $total),
                $albums ? trans_choice(':count album créé|:count albums créés', $albums) : null,
                $faces ? trans_choice(':count visage reconnu|:count visages reconnus', $faces) : null,
                $people ? trans_choice(':count fiche créée|:count fiches créées', $people) : null,
            ])),
        ]]];

        // Les plus vieilles photos entrées cette année
        $oldest = $photos->sortBy('taken_at')->take(3);
        if ($oldest->isNotEmpty() && $oldest->first()->taken_at->year < $year - 30) {
            $slides[] = ['type' => 'card', 'card' => ['icon' => '🕰️', 'title' => __('Redécouvertes'),
                'description' => __('Les plus anciennes photos entrées cette année')]];
            foreach ($oldest as $m) {
                $slides[] = ['type' => 'photo', 'media_id' => $m->id, 'chapter' => __('Redécouvert'), 'label' => (string) $m->taken_at->year];
                $picked->push($m->id);
            }
        }

        // Les voyages : un album « Voyage » qui a reçu des photos cette année
        $trips = \App\Models\Album::accessibleBy($user)->where('theme', 'voyage')
            ->whereHas('media', fn ($q) => $q->whereIn('media.id', $ids))
            ->withCount('media')->orderByDesc('media_count')->take(4)->get();
        foreach ($trips as $album) {
            $best = $photos->whereIn('id', DB::table('album_media')->where('album_id', $album->id)->pluck('media_id'))
                ->reject(fn ($m) => $picked->contains($m->id))->sortByDesc('matched')->first();
            if ($best) {
                $slides[] = ['type' => 'photo', 'media_id' => $best->id, 'chapter' => __('Voyage'), 'label' => $album->name];
                $picked->push($best->id);
            }
        }

        // Un moment par décennie (la photo où l'on reconnaît le plus de proches)
        $shownDecades = $photos->whereIn('id', $picked)->map(fn ($m) => intdiv($m->taken_at->year, 10) * 10)->unique();
        foreach ($photos->reject(fn ($m) => $picked->contains($m->id))
            ->groupBy(fn ($m) => intdiv($m->taken_at->year, 10) * 10)->sortKeys()
            ->reject(fn ($g, $decade) => $shownDecades->contains($decade)) as $decade => $group) {
            $best = $group->sortByDesc('matched')->first();
            $slides[] = ['type' => 'photo', 'media_id' => $best->id, 'chapter' => self::decade((int) $decade), 'label' => (string) $best->taken_at->year];
            $picked->push($best->id);
        }

        // Les plus présents
        $top = DB::table('media_person')->whereIn('media_id', $ids)
            ->whereIn('person_id', Person::inContext($user)->select('people.id'))
            ->groupBy('person_id')->selectRaw('person_id, count(*) as n')->orderByDesc('n')->limit(3)->get();
        $topPeople = Person::whereIn('id', $top->pluck('person_id'))
            ->withCount(['detectedFaces as matched_faces_count' => fn ($q) => $q->where('status', 'matched')])->get()->keyBy('id');
        foreach ($top as $i => $row) {
            $p = $topPeople[$row->person_id] ?? null;
            if (! $p) {
                continue;
            }
            $slides[] = ['type' => 'card', 'card' => [
                'icon' => null, 'date' => $i === 0 ? __('Les plus présents') : null,
                'title' => $this->firstName($p),
                'description' => trans_choice('sur :count photo ajoutée cette année|sur :count photos ajoutées cette année', (int) $row->n),
                'avatarUrl' => $p->avatarUrl(),
            ]];
        }

        $slides[] = ['type' => 'card', 'card' => [
            'icon' => '✨', 'title' => __('Merci !'),
            'description' => $contributors->isEmpty() ? null
                : __('Merci à :names d’avoir enrichi la mémoire de la famille.', ['names' => $this->listNames($contributors)]),
        ]];

        $media = Media::with('conversions')->whereIn('id', $picked)->get()->keyBy('id');
        $this->mediaService->hydrateSignedUrls($media->values());

        return ['title' => __('L’année :year', ['year' => $year]), 'slides' => $slides, 'media' => $media->values()];
    }

    /** Cache propre au visiteur ET à son cercle courant (les photos visibles en dépendent). */
    private function cacheKey(string $what, User $user): string
    {
        return "recaps:v3:{$what}:{$user->id}:".($user->currentCircle()?->id ?? 'none');
    }

    /** Médias ajoutés (cette année-là) que le visiteur voit, dans son cercle courant. */
    private function addedMedia(User $user, ?int $year = null)
    {
        return Media::query()->inContext($user)
            ->when($year, fn ($q) => $q->whereYear('media.created_at', $year));
    }

    private static function decade(int $decade): string
    {
        return app()->getLocale() === 'en'
            ? "{$decade}s"
            : __('Années :decade', ['decade' => $decade < 2000 ? substr((string) $decade, 2) : (string) $decade]);
    }

    /** « Paul », « Paul et Marie », « Paul, Marie et Jean ». */
    private function listNames(Collection $names): string
    {
        return $names->count() > 1
            ? $names->slice(0, -1)->implode(', ').' '.__('et').' '.$names->last()
            : (string) $names->first();
    }

    public function markSeen(User $user, string $key): void
    {
        DB::table('recap_views')->upsert(
            [['user_id' => $user->id, 'recap_key' => $key, 'seen_at' => now()]],
            ['user_id', 'recap_key'], ['seen_at']
        );
    }

    private function seen(User $user, string $key): bool
    {
        return DB::table('recap_views')->where('user_id', $user->id)->where('recap_key', $key)->exists();
    }

    /**
     * Photos datées que le visiteur peut voir, où l'on VOIT la personne : son
     * visage y est reconnu. Une simple mention (document, coupure de presse,
     * capture d'un livre identifiée sur sa fiche) ne compte pas.
     */
    private function taggedPhotos(User $user)
    {
        return DB::table('media_person as mp')
            ->join('media as m', 'm.id', '=', 'mp.media_id')
            ->whereNull('m.deleted_at')
            ->whereNotNull('m.taken_at')
            ->where('m.type', 'photo')
            ->whereExists(fn ($q) => $q->select(DB::raw(1))->from('detected_faces as seen')
                ->whereColumn('seen.media_id', 'm.id')->whereColumn('seen.person_id', 'mp.person_id')
                ->where('seen.status', 'matched'))
            ->where(fn ($q) => $q->whereNull('mp.fiche_status')->orWhere('mp.fiche_status', '!=', FichePublisher::PRIVATE))
            ->whereIn('m.id', Media::accessibleBy($user)->select('media.id'));
    }

    private function firstName(Person $p): string
    {
        return $p->first_name ?: explode(' ', (string) $p->name)[0];
    }
}
