<?php

namespace App\Services\Newsletter;

use App\Models\Media;
use App\Models\NewsletterSend;
use App\Models\Person;
use App\Models\QuestAnswer;
use App\Models\User;
use App\Models\VoiceNote;
use App\Services\CelebrationService;
use App\Services\GenealogyService;
use App\Services\MediaService;
use App\Services\NameDayService;
use App\Services\QuestService;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Newsletter hebdomadaire de la famille, composée pour UN destinataire.
 *
 * Trois étages, pour qu'elle ne soit jamais deux fois la même :
 *  - chiffres récurrents, montrés seulement s'ils sont non nuls (nouvelles
 *    photos, photos où l'on a été identifié, fêtes et anniversaires à venir,
 *    activité de la famille) ;
 *  - rubriques toujours là mais au contenu tournant (personne de la
 *    semaine, coup de pouce pour une frise de vie, fonctionnalité à
 *    découvrir), qui évitent ce que le destinataire a déjà reçu ;
 *  - une rubrique « bonus » tirée parmi d'autres (liens de famille à
 *    compléter, créer son cercle, souvenir d'il y a N ans, questions en
 *    attente, chiffres de la famille), jamais la même deux semaines de suite.
 *
 * Images en URL présignées 7 jours (un client mail n'a pas de session).
 */
class FamilyNewsletterBuilder
{
    public const EDITION = 'family';

    private const IMAGE_TTL_MINUTES = 60 * 24 * 7;

    /** Une personne de la semaine ne revient pas avant ce nombre d'envois. */
    private const PERSON_COOLDOWN = 26;

    private const NUDGE_COOLDOWN = 8;

    private const BONUS_POOL = ['tree_links', 'own_circle', 'years_ago', 'quests', 'numbers'];

    private User $user;

    private Carbon $now;

    private Carbon $since;

    private int $seed;

    /** @var Collection<int, NewsletterSend> */
    private Collection $history;

    public function __construct(
        private MediaService $media,
        private CelebrationService $celebrations,
        private GenealogyService $genealogy,
        private QuestService $quests,
    ) {}

    /**
     * Le numéro de la semaine pour ce compte, ou null s'il n'y a rien à dire.
     *
     * @return array{subject: string, preheader: string, sections: list<array>, log: list<array{key: string, ref: ?string}>}|null
     */
    public function build(User $user, ?Carbon $now = null): ?array
    {
        $this->user = $user;
        $this->now = ($now ?? now())->copy();
        $this->since = $this->now->copy()->subDays(7);
        $this->seed = crc32($user->id.$this->now->format('o-W'));
        $this->history = NewsletterSend::where('user_id', $user->id)
            ->where('edition', self::EDITION)
            ->orderByDesc('sent_at')
            ->limit(self::PERSON_COOLDOWN)
            ->get();

        $sections = array_values(array_filter([
            $this->newPhotos(),
            $this->taggedYou(),
            $this->upcoming(),
            $this->familyActivity(),
        ]));
        $hasNews = $sections !== [];

        $person = $this->personOfTheWeek();
        if ($person) {
            $sections[] = $person;
        }
        if ($bonus = $this->bonus()) {
            $sections[] = $bonus;
        }
        if ($nudge = $this->timelineNudge($person['person_id'] ?? null)) {
            $sections[] = $nudge;
        }

        // Rien de neuf ni personne à mettre à l'honneur : on n'écrit pas
        // pour ne rien dire (la fonctionnalité seule ne vaut pas un mail).
        if (! $hasNews && ! $person) {
            return null;
        }

        $sections[] = $this->spotlight();

        return [
            'subject' => $this->subject($sections),
            'preheader' => $this->preheader($sections),
            'sections' => $sections,
            'log' => array_map(fn ($s) => ['key' => $s['key'], 'ref' => $s['ref'] ?? null], $sections),
        ];
    }

    // ── Chiffres récurrents ────────────────────────────────────────────

    private function newPhotos(): ?array
    {
        $query = Media::accessibleBy($this->user)
            ->where('is_source', false)
            ->where('uploaded_at', '>=', $this->since)
            ->where('media.user_id', '!=', $this->user->id);
        $count = (clone $query)->count();
        if ($count === 0) {
            return null;
        }

        $uploaders = User::whereIn('id', (clone $query)->select('media.user_id'))
            ->orderBy('name')->pluck('name')
            ->map(fn ($n) => strtok($n, ' '))->unique()->values()->all();

        return [
            'key' => 'new_photos',
            'count' => $count,
            'uploaders' => $uploaders,
            'thumbs' => $this->thumbs((clone $query)->where('type', 'photo')->orderByDesc('uploaded_at'), 4),
            'url' => url('/media'),
        ];
    }

    private function taggedYou(): ?array
    {
        if (! $this->user->person_id) {
            return null;
        }

        $query = Media::accessibleBy($this->user)
            ->whereHas('people', fn ($p) => $p->where('people.id', $this->user->person_id)
                ->where('media_person.created_at', '>=', $this->since));
        $count = (clone $query)->count();
        if ($count === 0) {
            return null;
        }

        return [
            'key' => 'tagged_you',
            'count' => $count,
            'thumbs' => $this->thumbs((clone $query)->where('type', 'photo'), 3),
            'url' => url("/people/{$this->user->person_id}"),
        ];
    }

    /** Anniversaires, fêtes des prénoms et souvenirs des 7 prochains jours (cercle proche). */
    private function upcoming(): ?array
    {
        $today = $this->now->copy()->startOfDay();
        $circle = fn () => $this->celebrations->closeCircleQuery($this->user);
        $items = [];

        foreach ($this->celebrations->upcomingBirthdays($today, 7, $circle()) as $b) {
            $items[] = ['kind' => 'birthday', 'days' => $b['days_until'], 'name' => $this->shortName($b['person']),
                'text' => "{$b['age']} ans", 'url' => url("/people/{$b['person']->id}")];
        }

        $names = app(NameDayService::class);
        for ($d = 0; $d < 7; $d++) {
            $day = $today->copy()->addDays($d);
            $wanted = $names->namesFor($day);
            if ($wanted === []) {
                continue;
            }
            foreach ($circle()->whereNull('death_date')->get() as $p) {
                $first = strtok((string) ($p->first_name ?: $p->name), ' ');
                if ($first && in_array(NameDayService::normalize($first), $wanted, true)) {
                    $items[] = ['kind' => 'nameday', 'days' => $d, 'name' => $this->shortName($p),
                        'text' => 'bonne fête', 'url' => url("/people/{$p->id}")];
                }
            }
        }

        foreach ($this->celebrations->upcomingMemorials($today, 7, $circle()) as $m) {
            $items[] = ['kind' => 'memorial', 'days' => $m['days_until'], 'name' => $this->shortName($m['person']),
                'text' => 'en mémoire, '.($m['years'] === 1 ? 'un an' : "{$m['years']} ans"), 'url' => url("/people/{$m['person']->id}")];
        }

        if ($items === []) {
            return null;
        }
        usort($items, fn ($a, $b) => $a['days'] <=> $b['days']);
        foreach ($items as &$item) {
            $item['when'] = $this->dayLabel($today->copy()->addDays($item['days']), $item['days']);
        }

        return ['key' => 'upcoming', 'items' => array_slice($items, 0, 8)];
    }

    /** Ce que la famille a fait cette semaine (souvenirs racontés, fiches, réponses). */
    private function familyActivity(): ?array
    {
        $voice = VoiceNote::where('created_at', '>=', $this->since)
            ->whereIn('media_id', Media::accessibleBy($this->user)->select('media.id'))
            ->with('person')
            ->get();
        $newPeople = Person::where('in_family_directory', true)->where('created_at', '>=', $this->since)->count();
        // Seulement ce que le destinataire peut voir / ses proches ont fait.
        $members = DB::table('household_user')
            ->whereIn('household_id', $this->user->householdIds())
            ->pluck('user_id')->push($this->user->id)->unique();
        $answers = QuestAnswer::where('answer_kind', 'answered')->where('created_at', '>=', $this->since)
            ->whereIn('user_id', $members)->count();
        $identified = DB::table('media_person')->where('created_at', '>=', $this->since)
            ->whereIn('media_id', Media::accessibleBy($this->user)->select('media.id'))->count();

        $lines = array_values(array_filter([
            $voice->isNotEmpty() ? [
                'count' => $voice->count(),
                'label' => $voice->count() === 1 ? 'souvenir raconté à voix haute' : 'souvenirs racontés à voix haute',
                'detail' => 'par '.$this->joinNames($voice->map(fn ($v) => $v->person ? $this->shortName($v->person) : null)->filter()->unique()->values()->all()),
            ] : null,
            $identified > 0 ? ['count' => $identified, 'label' => $identified === 1 ? 'visage identifié' : 'visages identifiés', 'detail' => null] : null,
            $newPeople > 0 ? ['count' => $newPeople, 'label' => $newPeople === 1 ? 'nouvelle fiche dans l\'arbre' : 'nouvelles fiches dans l\'arbre', 'detail' => null] : null,
            $answers > 0 ? ['count' => $answers, 'label' => $answers === 1 ? 'petite question résolue' : 'petites questions résolues', 'detail' => null] : null,
        ]));

        return $lines === [] ? null : ['key' => 'family_activity', 'lines' => $lines];
    }

    // ── Rubriques tournantes ────────────────────────────────────────────

    private function personOfTheWeek(): ?array
    {
        $recent = $this->recentRefs('person_of_week', self::PERSON_COOLDOWN);
        $candidates = Person::visibleTo($this->user)
            ->where('in_family_directory', true)
            ->whereNotNull('portrait_path')
            ->when($this->user->person_id, fn ($q) => $q->where('people.id', '!=', $this->user->person_id))
            ->whereNotIn('people.id', $recent)
            ->withCount(['media as photos_count' => fn ($q) => $q->accessibleBy($this->user)])
            ->get()
            ->filter(fn ($p) => $p->photos_count >= 3)
            ->values();
        if ($candidates->isEmpty()) {
            return null;
        }

        // Les proches d'abord (≤ 4 liens), tirage stable sur la semaine.
        $distance = $this->genealogy->proximity($this->user->person_id)['distance'];
        $close = $candidates->filter(fn ($p) => ($distance[$p->id] ?? 99) <= 4)->values();
        $pool = $close->isNotEmpty() ? $close : $candidates;
        $person = $pool[$this->seed % $pool->count()];

        return [
            'key' => 'person_of_week',
            'ref' => $person->id,
            'person_id' => $person->id,
            'name' => $person->name,
            'first_name' => $this->shortName($person),
            'relation' => $this->relation($person),
            'life' => $this->lifeLine($person),
            'photos_count' => $person->photos_count,
            'portrait' => $this->portraitUrl($person),
            'thumbs' => $this->thumbs(
                Media::accessibleBy($this->user)->where('type', 'photo')
                    ->whereHas('people', fn ($p) => $p->where('people.id', $person->id))
                    ->orderByRaw('md5(media.id::text || ?)', [(string) $this->seed]),
                3,
            ),
            'url' => url("/people/{$person->id}"),
        ];
    }

    /** « Que savez-vous de la vie de X ? » : une fiche proche à la frise presque vide. */
    private function timelineNudge(?string $exclude): ?array
    {
        $distance = $this->genealogy->proximity($this->user->person_id)['distance'];
        $ids = array_keys(array_filter($distance, fn ($d) => $d <= 3));
        if ($ids === []) {
            return null;
        }

        $recent = $this->recentRefs('timeline', self::NUDGE_COOLDOWN);
        $people = Person::whereIn('id', $ids)
            ->where('in_family_directory', true)
            ->whereNotIn('id', array_filter([...$recent, $exclude]))
            ->withCount('lifeEvents')
            ->get()
            // Une vie à raconter : adulte (ou âge inconnu), frise presque vide.
            ->filter(fn ($p) => $p->life_events_count <= 1
                && ($p->birth_date === null || $p->birth_date->diffInYears($this->now) >= 16))
            ->sortBy(fn ($p) => ($distance[$p->id] ?? 9).'-'.$p->id)
            ->values();
        if ($people->isEmpty()) {
            return null;
        }

        $person = $people[$this->seed % min(5, $people->count())];
        $self = $person->id === $this->user->person_id;

        return [
            'key' => 'timeline',
            'ref' => $person->id,
            'self' => $self,
            'name' => $this->shortName($person),
            'relation' => $self ? null : $this->relation($person),
            'portrait' => $this->portraitUrl($person),
            'prompts' => $self
                ? ['vos écoles et diplômes', 'vos métiers', 'les maisons où vous avez vécu']
                : ['un métier', 'une école', 'une maison où '.($person->gender === 'F' ? 'elle' : 'il').' a vécu', 'une anecdote'],
            'url' => url("/people/{$person->id}"),
        ];
    }

    private function spotlight(): array
    {
        $shown = $this->recentRefs('spotlight', count(FeatureSpotlights::ALL));
        $key = FeatureSpotlights::next($shown, crc32($this->user->id));

        return ['key' => 'spotlight', 'ref' => $key] + FeatureSpotlights::ALL[$key]
            + ['url_abs' => url(FeatureSpotlights::ALL[$key]['url'])];
    }

    /** Une rubrique bonus éligible, différente de celle de la semaine dernière. */
    private function bonus(): ?array
    {
        $lastBonus = collect($this->history->first()?->sections ?? [])
            ->pluck('key')->intersect(self::BONUS_POOL)->first();

        $order = self::BONUS_POOL;
        mt_srand($this->seed);
        shuffle($order);
        mt_srand();

        foreach ($order as $key) {
            if ($key === $lastBonus) {
                continue;
            }
            $section = match ($key) {
                'tree_links' => $this->treeLinks(),
                'own_circle' => $this->ownCircle(),
                'years_ago' => $this->yearsAgo(),
                'quests' => $this->pendingQuests(),
                'numbers' => $this->numbers(),
            };
            if ($section) {
                return $section;
            }
        }

        return null;
    }

    /** Ses propres liens de famille à compléter (parents, frères et sœurs, conjoint). */
    private function treeLinks(): ?array
    {
        if ($this->recentRefs('tree_links', 4) !== []) {
            return null;
        }
        $me = $this->user->person_id ? Person::find($this->user->person_id) : null;
        if (! $me) {
            return ['key' => 'tree_links', 'ref' => 'link_self', 'missing' => 'link_self', 'url' => url('/people')];
        }

        $missing = match (true) {
            ! $me->father_id && ! $me->mother_id => 'parents',
            ($me->father_id || $me->mother_id) && ! Person::where('id', '!=', $me->id)
                ->where(fn ($q) => $q->when($me->father_id, fn ($w) => $w->orWhere('father_id', $me->father_id))
                    ->when($me->mother_id, fn ($w) => $w->orWhere('mother_id', $me->mother_id)))->exists() => 'siblings',
            ! DB::table('person_relationships')->where('person1_id', $me->id)->orWhere('person2_id', $me->id)->exists()
                && ($me->birth_date?->diffInYears($this->now) ?? 30) >= 22 => 'partner',
            default => null,
        };

        return $missing ? ['key' => 'tree_links', 'ref' => $missing, 'missing' => $missing, 'url' => url("/people/{$me->id}")] : null;
    }

    private function ownCircle(): ?array
    {
        if ($this->recentRefs('own_circle', self::NUDGE_COOLDOWN) !== []
            || $this->user->circles()->where('kind', \App\Models\Household::KIND_FRIENDS)->isNotEmpty()) {
            return null;
        }

        return ['key' => 'own_circle', 'url' => url('/households')];
    }

    /** Une photo prise cette même semaine, une autre année. */
    private function yearsAgo(): ?array
    {
        $media = Media::accessibleBy($this->user)
            ->where('type', 'photo')
            ->whereNotNull('taken_at')
            ->whereRaw('EXTRACT(WEEK FROM taken_at) = ?', [(int) $this->now->format('W')])
            ->whereRaw('EXTRACT(YEAR FROM taken_at) < ?', [$this->now->year])
            ->with(['conversions', 'people'])
            ->orderByRaw('md5(media.id::text || ?)', [(string) $this->seed])
            ->first();
        if (! $media) {
            return null;
        }

        $years = $this->now->year - $media->taken_at->year;

        return [
            'key' => 'years_ago',
            'ref' => $media->id,
            'years' => $years,
            'date' => $media->taken_at->locale('fr')->translatedFormat('F Y'),
            'who' => $this->joinNames($media->people->take(4)->map(fn ($p) => $this->shortName($p))->all()),
            'image' => $this->media->thumbnailUrl($media, ['medium', 'small'], self::IMAGE_TTL_MINUTES, presigned: true),
            'url' => url("/media/{$media->id}"),
        ];
    }

    private function pendingQuests(): ?array
    {
        $count = count($this->quests->candidates($this->user));

        return $count > 0 ? ['key' => 'quests', 'count' => $count, 'url' => url('/dashboard')] : null;
    }

    private function numbers(): ?array
    {
        $photos = Media::accessibleBy($this->user)->where('type', 'photo')->count();
        if ($photos === 0) {
            return null;
        }

        return [
            'key' => 'numbers',
            'photos' => $photos,
            'people' => Person::where('in_family_directory', true)->count(),
            'with_photos' => Person::where('in_family_directory', true)->whereNotNull('portrait_path')->count(),
            'oldest' => Media::accessibleBy($this->user)->where('type', 'photo')->whereNotNull('taken_at')->min('taken_at'),
        ];
    }

    // ── Outils ─────────────────────────────────────────────────────────

    /** @return list<string> réfs d'une rubrique dans les $n derniers envois */
    private function recentRefs(string $key, int $n): array
    {
        return $this->history->take($n)
            ->flatMap(fn ($send) => collect($send->sections)->where('key', $key)->pluck('ref'))
            ->filter()->values()->all();
    }

    private function thumbs($query, int $n): array
    {
        return $query->with('conversions')->limit($n)->get()
            ->map(fn (Media $m) => $this->media->thumbnailUrl($m, ['small', 'thumbnail'], self::IMAGE_TTL_MINUTES, presigned: true))
            ->values()->all();
    }

    private function portraitUrl(Person $person): ?string
    {
        if (! $person->portrait_path) {
            return null;
        }
        try {
            return Storage::temporaryUrl($person->portrait_path, now()->addMinutes(self::IMAGE_TTL_MINUTES));
        } catch (\Throwable) {
            return null;
        }
    }

    private function relation(Person $person): ?string
    {
        if (! $this->user->person_id) {
            return null;
        }
        $path = $this->genealogy->pathBetween($this->user->person_id, $person->id);

        return $path ? GenealogyService::relationLabel($path['edges'], $person->gender) : null;
    }

    /** « née en 1958 à Mennecy » / « 1920 – 1998 ». */
    private function lifeLine(Person $person): ?string
    {
        if (! $person->birth_date) {
            return null;
        }
        if ($person->death_date) {
            return $person->birth_date->year.' – '.$person->death_date->year;
        }
        $e = $person->gender === 'F' ? 'e' : '';

        return "né{$e} en {$person->birth_date->year}".($person->birth_place ? ' à '.strtok($person->birth_place, ',') : '');
    }

    private function shortName(Person $person): string
    {
        return strtok(trim($person->first_name ?: $person->name), ' ') ?: $person->name;
    }

    private function joinNames(array $names): string
    {
        $names = array_values(array_filter($names));

        return count($names) < 2 ? implode('', $names) : implode(', ', array_slice($names, 0, -1)).' et '.end($names);
    }

    private function dayLabel(Carbon $day, int $days): string
    {
        return match ($days) {
            0 => 'aujourd\'hui',
            1 => 'demain',
            default => $day->locale('fr')->translatedFormat('l j'),
        };
    }

    private function subject(array $sections): string
    {
        $by = collect($sections)->keyBy('key');
        $parts = [];
        if ($p = $by->get('new_photos')) {
            $parts[] = $p['count'] === 1 ? '1 nouvelle photo' : "{$p['count']} nouvelles photos";
        }
        if ($b = collect($by->get('upcoming')['items'] ?? [])->firstWhere('kind', 'birthday')) {
            $parts[] = "l'anniversaire de {$b['name']}";
        }
        if ($p = $by->get('person_of_week')) {
            $parts[] = $p['first_name'].' à l\'honneur';
        }

        return $parts === []
            ? 'Cette semaine en famille'
            : 'Cette semaine en famille : '.$this->joinNames(array_slice($parts, 0, 2));
    }

    private function preheader(array $sections): string
    {
        $by = collect($sections)->keyBy('key');
        if ($t = $by->get('tagged_you')) {
            return $t['count'] === 1 ? 'Vous apparaissez sur une nouvelle photo.' : "Vous apparaissez sur {$t['count']} nouvelles photos.";
        }
        if ($s = $by->get('spotlight')) {
            $title = mb_strtolower(mb_substr($s['title'], 0, 1)).mb_substr($s['title'], 1);

            return 'À découvrir : '.$title.(preg_match('/[?!.]$/u', $title) ? '' : '.');
        }

        return 'Les nouvelles de la famille sur MemoryLane.';
    }
}
