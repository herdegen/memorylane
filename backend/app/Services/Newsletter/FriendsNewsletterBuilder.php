<?php

namespace App\Services\Newsletter;

use App\Enums\QuestType;
use App\Models\Household;
use App\Models\Media;
use App\Models\NewsletterSend;
use App\Models\Person;
use App\Models\User;
use App\Services\CelebrationService;
use App\Services\MediaService;
use App\Services\NetworkService;
use App\Services\QuestService;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Support\Traits\Localizable;

/**
 * Lettre du dimanche, édition « amis », composée pour UN destinataire qui
 * n'est que dans des cercles d'amis. Tout vient STRICTEMENT de ses cercles
 * d'amis : jamais rien du monde famille (ni arbre, ni fiches de la famille,
 * ni adresses, ni photos qui ne concernent pas le cercle).
 *
 * Elle ne part que s'il y a du nouveau : nouvelles photos du cercle, photos
 * où l'on a été identifié, anniversaires des 7 prochains jours ou souvenir
 * d'il y a N ans. S'y ajoutent, sans suffire à justifier un envoi : la
 * question « comment as-tu connu X ? », les visages « Qui est-ce ? »
 * en attente (de préférence ceux d'un album du cercle où il apparaît) et
 * une fonctionnalité à découvrir (sans arbre ni généalogie).
 *
 * Photos du cercle : partagées au cercle (directement ou dans un album
 * partagé au cercle) ou montrant un ami du cercle hors famille, toujours
 * parmi celles que le destinataire peut voir. Pour un membre de la famille
 * (encart « Côté amis » de la lettre famille, cf. corner()), seules
 * comptent les photos partagées au cercle : les autres sont déjà dans la
 * lettre famille.
 *
 * Images en URL présignées 7 jours (un client mail n'a pas de session).
 */
class FriendsNewsletterBuilder
{
    use Localizable, NewsletterHelpers;

    public const EDITION = 'friends';

    private const IMAGE_TTL_MINUTES = 60 * 24 * 7;

    /** Envois relus pour varier (souvenirs, découvertes, objets). */
    private const HISTORY_SIZE = 26;

    /** Le même ami ne revient pas dans « comment as-tu connu X ? » avant N envois. */
    private const HOW_MET_COOLDOWN = 4;

    /** Formulations de l'objet, jamais la même deux envois de suite. */
    private const SUBJECTS = ['with', 'news', 'week'];

    private User $user;

    private Carbon $now;

    private Carbon $since;

    private int $seed;

    /** @var Collection<int, Household> */
    private Collection $circles;

    /** @var list<string> */
    private array $circleIds = [];

    /** @var list<string> fiches d'amis des cercles, hors famille et hors soi */
    private array $friendIds = [];

    private bool $inFamily = false;

    /** @var Collection<int, NewsletterSend> */
    private Collection $history;

    /** @var Collection<int, Household>|null */
    private ?Collection $active = null;

    public function __construct(
        private MediaService $media,
        private CelebrationService $celebrations,
        private QuestService $quests,
        private NetworkService $network,
        private AlbumFacesFinder $facesAlbums,
    ) {}

    /**
     * La lettre de la semaine pour ce compte, ou null s'il n'y a rien de neuf
     * dans ses cercles d'amis.
     *
     * @return array{subject: string, preheader: string, circle: string, sections: list<array>, log: list<array{key: string, ref: ?string}>}|null
     */
    public function build(User $user, ?Carbon $now = null): ?array
    {
        // Rédigée dans la langue du destinataire (textes et dates).
        return $this->withLocale($user->preferredLocale(), fn () => $this->compose($user, $now));
    }

    /**
     * Encart « Côté amis » de la lettre famille (membres à la fois en famille
     * et dans un cercle d'amis), dans la langue courante ; null s'il n'y a
     * rien de neuf dans leurs cercles d'amis.
     */
    public function corner(User $user, ?Carbon $now = null): ?array
    {
        if (! $this->prepare($user, $now, withHistory: false)) {
            return null;
        }

        $sections = $this->news();
        if ($sections === []) {
            return null;
        }
        $by = collect($sections)->keyBy('key');
        $photos = $by->get('new_photos');
        $tagged = $by->get('tagged_you');
        $memory = $by->get('years_ago');

        return [
            'key' => 'friends',
            'circle' => $this->circleLabel(),
            'photos' => $photos['count'] ?? 0,
            'thumbs' => $photos['thumbs'] ?? $tagged['thumbs'] ?? array_values(array_filter([$memory['image'] ?? null])),
            'tagged' => $tagged['count'] ?? 0,
            'birthdays' => array_map(fn ($b) => ['name' => $b['first_name'], 'when' => $b['when']], array_slice($by->get('birthdays')['items'] ?? [], 0, 3)),
            'memory_years' => $memory['years'] ?? null,
            'topic' => $this->topics($sections)[0],
            'url' => $this->circleUrl(),
        ];
    }

    private function compose(User $user, ?Carbon $now): ?array
    {
        if (! $this->prepare($user, $now, withHistory: true)) {
            return null;
        }

        $sections = $this->news();
        // Rien de neuf : pas de lettre (les relances et la découverte seules
        // ne valent pas un mail).
        if ($sections === []) {
            return null;
        }

        foreach ([$this->howMet(), $this->faces()] as $nudge) {
            if ($nudge) {
                $sections[] = $nudge;
            }
        }
        $sections[] = $this->spotlight($sections);

        $variant = $this->subjectVariant();
        $log = array_map(fn ($s) => ['key' => $s['key'], 'ref' => $s['ref'] ?? null], $sections);
        $log[] = ['key' => 'subject', 'ref' => $variant];

        return [
            'subject' => $this->subject($sections, $variant),
            'preheader' => $this->preheader($sections),
            'circle' => $this->circleLabel(),
            'sections' => $sections,
            'log' => $log,
        ];
    }

    private function prepare(User $user, ?Carbon $now, bool $withHistory): bool
    {
        $this->user = $user;
        $this->now = ($now ?? now())->copy();
        $this->since = $this->now->copy()->subDays(7);
        $this->seed = crc32($user->id.$this->now->format('o-W'));
        $this->active = null;
        $this->circles = Household::whereIn('id', $user->friendCircleIds())->orderBy('name')->get();
        $this->circleIds = $this->circles->pluck('id')->all();
        if ($this->circleIds === []) {
            return false;
        }

        $this->inFamily = $user->circles()->contains('kind', Household::KIND_FAMILY);
        $this->friendIds = $this->inFamily ? [] : $this->circlePeople()
            ->where('people.in_family_directory', false)
            ->pluck('people.id')->all();
        $this->history = $withHistory
            ? NewsletterSend::where('user_id', $user->id)
                ->where('edition', self::EDITION)
                ->orderByDesc('sent_at')
                ->limit(self::HISTORY_SIZE)
                ->get()
            : collect();

        return true;
    }

    /** @return list<array> les rubriques « du nouveau », dans l'ordre de la lettre */
    private function news(): array
    {
        return array_values(array_filter([
            $this->newPhotos(),
            $this->taggedYou(),
            $this->birthdays(),
            $this->yearsAgo(),
        ]));
    }

    // ── Du nouveau ─────────────────────────────────────────────────────

    private function newPhotos(): ?array
    {
        $query = $this->newThisWeek($this->circleMedia())
            ->where('media.user_id', '!=', $this->user->id);
        $count = (clone $query)->count();
        if ($count === 0) {
            return null;
        }

        // Prénoms des seuls membres (ou créateurs) des cercles : aucun nom
        // venu d'ailleurs.
        $members = DB::table('household_user')->whereIn('household_id', $this->circleIds)->pluck('user_id')
            ->merge($this->circles->pluck('created_by'))->filter()->unique()->values();
        $uploaders = User::whereIn('id', (clone $query)->select('media.user_id'))
            ->whereIn('id', $members)
            ->orderBy('name')->pluck('name')
            ->map(fn ($n) => strtok($n, ' '))->unique()->values()->all();

        return [
            'key' => 'new_photos',
            'count' => $count,
            'uploaders' => $uploaders,
            'thumbs' => $this->thumbs((clone $query)->where('media.type', 'photo')->orderByDesc('media.uploaded_at'), 4),
            'url' => $this->circleUrl(),
        ];
    }

    private function taggedYou(): ?array
    {
        if (! $this->user->person_id) {
            return null;
        }

        $query = $this->circleMedia()
            ->whereHas('people', fn ($p) => $p->where('people.id', $this->user->person_id)
                ->where('media_person.created_at', '>=', $this->since));
        $count = (clone $query)->count();
        if ($count === 0) {
            return null;
        }

        return [
            'key' => 'tagged_you',
            'count' => $count,
            'thumbs' => $this->thumbs((clone $query)->where('media.type', 'photo')->orderByDesc('media.uploaded_at'), 3),
            'url' => url("/people/{$this->user->person_id}"),
        ];
    }

    /** Anniversaires des amis du cercle dans les 7 prochains jours (sans âge, comme l'accueil entre amis). */
    private function birthdays(): ?array
    {
        $today = $this->now->copy()->startOfDay();
        $people = $this->circlePeople()
            // Membre de la famille : les fiches de la famille sont déjà dans sa lettre.
            ->when($this->inFamily, fn ($q) => $q->where('people.in_family_directory', false));

        $items = $this->celebrations->upcomingBirthdays($today, 7, $people)
            ->sortBy(fn ($b) => sprintf('%02d-%s', $b['days_until'], $b['person']->name))
            ->take(6)
            ->map(fn ($b) => [
                'days' => $b['days_until'],
                'name' => $b['person']->name,
                'first_name' => $this->shortName($b['person']),
                'when' => $this->dayLabel($today->copy()->addDays($b['days_until']), $b['days_until']),
                'url' => url("/people/{$b['person']->id}"),
            ])
            ->values()->all();

        return $items === [] ? null : ['key' => 'birthdays', 'items' => $items];
    }

    /** Une photo du cercle prise cette même semaine, une autre année. */
    private function yearsAgo(): ?array
    {
        $media = $this->circleMedia()
            ->where('media.type', 'photo')
            ->whereNotNull('media.taken_at')
            ->exactDate()
            ->whereRaw('EXTRACT(WEEK FROM media.taken_at) = ?', [(int) $this->now->format('W')])
            ->whereRaw('EXTRACT(YEAR FROM media.taken_at) < ?', [$this->now->year])
            ->whereNotIn('media.id', $this->recentRefs('years_ago', self::HISTORY_SIZE))
            ->with(['conversions', 'people'])
            ->orderByRaw('md5(media.id::text || ?)', [(string) $this->seed])
            ->first();
        if (! $media) {
            return null;
        }

        // Qui est dessus : seulement les amis du cercle.
        $friends = $this->circlePeople()->pluck('people.id')->flip();

        return [
            'key' => 'years_ago',
            'ref' => $media->id,
            'years' => $this->now->year - $media->taken_at->year,
            'date' => $media->taken_at->locale(app()->getLocale())->translatedFormat('F Y'),
            'who' => $this->joinNames($media->people->filter(fn ($p) => isset($friends[$p->id]))
                ->take(4)->map(fn ($p) => $this->shortName($p))->all()),
            'image' => $this->media->thumbnailUrl($media, ['medium', 'small'], self::IMAGE_TTL_MINUTES, presigned: true),
            'url' => url("/media/{$media->id}"),
        ];
    }

    // ── Relances et découverte (ne justifient pas un envoi) ───────────

    /** « Comment as-tu connu X ? » : la quête how_met du cercle, s'il en reste une. */
    private function howMet(): ?array
    {
        if (! $this->user->person_id) {
            return null;
        }

        $recent = $this->recentRefs('how_met', self::HOW_MET_COOLDOWN);
        foreach ($this->circles as $circle) {
            $viewer = $this->user->inCircle($circle->id);
            $ids = collect($this->quests->candidates($viewer))
                ->where('type', QuestType::HowMet->value)
                ->pluck('subject_id')
                ->diff($recent)
                ->sort()->values();
            if ($ids->isEmpty()) {
                continue;
            }

            $friend = Person::visibleTo($this->user)->find($ids[$this->seed % $ids->count()]);
            if (! $friend) {
                continue;
            }

            return [
                'key' => 'how_met',
                'ref' => $friend->id,
                'name' => $this->shortName($friend),
                'photos' => $this->network->coTagged($viewer, $this->user->person_id)[$friend->id][0] ?? 0,
                'url' => url("/people/{$friend->id}"),
            ];
        }

        return null;
    }

    /**
     * Visages « Qui est-ce ? » en attente : ceux d'un album du cercle où le
     * destinataire apparaît s'il y en a assez, sinon tous ceux des photos du
     * cercle. Une lettre sur deux au plus : un rappel, pas une rengaine.
     */
    private function faces(): ?array
    {
        $last = collect($this->history->first()?->sections ?? [])->pluck('key');
        if ($last->contains('faces') || $last->contains('faces_album')) {
            return null;
        }

        return $this->facesAlbum() ?? $this->circleFaces();
    }

    /** « Il y a 5 visages non identifiés dans l'album Y, où tu apparais » : un album partagé avec l'un de ses cercles d'amis. */
    private function facesAlbum(): ?array
    {
        $recent = $this->recentRefs('faces_album', AlbumFacesFinder::COOLDOWN);
        foreach ($this->circles as $circle) {
            $found = $this->facesAlbums->find(
                $this->user->inCircle($circle->id),
                fn ($albums) => $albums->whereHas('households', fn ($h) => $h->whereKey($circle->id)),
                $recent,
                $this->seed,
            );
            if ($found) {
                return $this->facesAlbumSection($found);
            }
        }

        return null;
    }

    /** Tous les visages en attente sur les photos de ses cercles. */
    private function circleFaces(): ?array
    {
        $count = 0;
        foreach ($this->circles as $circle) {
            $count = max($count, $this->quests->faceQuestions($this->user->inCircle($circle->id), null, [], 0)['total']);
        }

        return $count === 0 ? null : [
            'key' => 'faces',
            'count' => $count,
            // Au-delà, le jeu affiche « 200+ ».
            'more' => $count >= QuestService::GAME_SCAN_LIMIT,
            'url' => url('/quests/faces'),
        ];
    }

    /**
     * Découverte du site, jamais sur une page déjà proposée plus haut dans
     * la lettre (ex. le jeu « Qui est-ce ? » quand ses visages sont relancés).
     */
    private function spotlight(array $sections): array
    {
        $all = FeatureSpotlights::friends();
        $taken = collect($sections)->pluck('url')->filter()->map(fn ($u) => parse_url($u, PHP_URL_PATH))->all();
        $keys = array_keys(array_filter($all, fn ($f) => ! in_array($f['url'], $taken, true))) ?: array_keys($all);
        $shown = $this->recentRefs('spotlight', count($all));
        $key = FeatureSpotlights::next($shown, crc32($this->user->id), $keys);

        return ['key' => 'spotlight', 'ref' => $key] + $all[$key]
            + ['url_abs' => url($all[$key]['url'])];
    }

    // ── Périmètre : les cercles d'amis du destinataire ─────────────────

    /** Fiches rattachées à ses cercles d'amis (sauf la sienne). */
    private function circlePeople()
    {
        return Person::visibleTo($this->user)
            ->whereHas('circles', fn ($c) => $c->whereIn('households.id', $this->circleIds))
            ->when($this->user->person_id, fn ($q) => $q->where('people.id', '!=', $this->user->person_id));
    }

    /**
     * Photos du cercle que le destinataire peut voir (hors fichiers sources
     * remplacés par leurs clips).
     *
     * @param  list<string>|null  $ids  cercles visés (défaut : tous ses cercles d'amis)
     */
    private function circleMedia(?array $ids = null)
    {
        $ids ??= $this->circleIds;
        $shared = fn ($h) => $h->whereIn('households.id', $ids);

        return Media::accessibleBy($this->user)
            ->where('media.is_source', false)
            ->where(function ($w) use ($shared) {
                $w->whereHas('households', $shared)
                    ->orWhereHas('albums', fn ($a) => $a->whereHas('households', $shared));
                if ($this->friendIds !== []) {
                    $w->orWhereHas('people', fn ($p) => $p->whereIn('people.id', $this->friendIds));
                }
            });
    }

    /**
     * Arrivées dans le cercle cette semaine : envoyées cette semaine, ou
     * partagées cette semaine avec le cercle (directement ou via un album).
     */
    private function newThisWeek($query, ?array $ids = null)
    {
        $ids ??= $this->circleIds;
        $since = $this->since;

        return $query->where(fn ($w) => $w->where('media.uploaded_at', '>=', $since)
            ->orWhereExists(fn ($q) => $q->selectRaw('1')->from('household_media as hm')
                ->whereColumn('hm.media_id', 'media.id')
                ->whereIn('hm.household_id', $ids)
                ->where('hm.created_at', '>=', $since))
            ->orWhereExists(fn ($q) => $q->selectRaw('1')->from('album_media as am')
                ->join('album_household as ah', 'ah.album_id', '=', 'am.album_id')
                ->whereColumn('am.media_id', 'media.id')
                ->whereIn('ah.household_id', $ids)
                ->where(fn ($t) => $t->where('am.created_at', '>=', $since)->orWhere('ah.created_at', '>=', $since))));
    }

    /** Cercles d'où vient la nouveauté : ceux qui ont de nouvelles photos, sinon tous. */
    private function activeCircles(): Collection
    {
        if ($this->circles->count() === 1) {
            return $this->circles;
        }

        return $this->active ??= (function () {
            $withPhotos = $this->circles->filter(fn ($c) => $this->newThisWeek($this->circleMedia([$c->id]), [$c->id])
                ->where('media.user_id', '!=', $this->user->id)->exists())->values();

            return $withPhotos->isNotEmpty() ? $withPhotos : $this->circles;
        })();
    }

    /** « La bande à Milou », « Les Lyonnais et Le judo », ou « tes amis » au-delà. */
    private function circleLabel(): string
    {
        $names = $this->activeCircles()->pluck('name')->all();

        return count($names) <= 2 ? $this->joinNames($names) : __('tes amis');
    }

    /** La galerie si le cercle est déjà le cercle courant du compte, sinon la page du cercle. */
    private function circleUrl(): string
    {
        $circle = $this->activeCircles()->first();

        return $this->user->currentCircle()?->id === $circle->id
            ? url('/media')
            : url("/households/{$circle->id}");
    }

    // ── Objet et pré-en-tête ───────────────────────────────────────────

    private function subjectVariant(): string
    {
        $last = collect($this->history->first()?->sections ?? [])->firstWhere('key', 'subject')['ref'] ?? null;
        $pool = array_values(array_diff(self::SUBJECTS, [$last]));

        return $pool[$this->seed % count($pool)];
    }

    private function subject(array $sections, string $variant): string
    {
        $values = ['circle' => $this->circleLabel(), 'topics' => $this->joinNames(array_slice($this->topics($sections), 0, 2))];

        return Str::ucfirst(match ($variant) {
            'news' => __('Des nouvelles de :circle : :topics', $values),
            'week' => __(':circle cette semaine : :topics', $values),
            default => __('Cette semaine avec :circle : :topics', $values),
        });
    }

    /** @return list<string> sujets de l'objet, du plus parlant au moins parlant */
    private function topics(array $sections): array
    {
        $by = collect($sections)->keyBy('key');
        $topics = [];
        if ($p = $by->get('new_photos')) {
            $topics[] = $p['count'] === 1 ? __('1 nouvelle photo') : __(':count nouvelles photos', ['count' => $p['count']]);
        }
        if ($t = $by->get('tagged_you')) {
            $topics[] = trans_choice(':count photo de toi|:count photos de toi', $t['count']);
        }
        if ($b = $by->get('birthdays')) {
            $topics[] = __('l\'anniversaire de :name', ['name' => $b['items'][0]['first_name']]);
        }
        if ($y = $by->get('years_ago')) {
            $topics[] = trans_choice('un souvenir d’il y a :count an|un souvenir d’il y a :count ans', $y['years']);
        }

        return $topics;
    }

    private function preheader(array $sections): string
    {
        $by = collect($sections)->keyBy('key');
        if ($t = $by->get('tagged_you')) {
            return $t['count'] === 1
                ? __('Tu apparais sur une nouvelle photo.')
                : __('Tu apparais sur :count nouvelles photos.', ['count' => $t['count']]);
        }
        if ($b = $by->get('birthdays')) {
            return __('N’oublie pas l’anniversaire de :name.', ['name' => $b['items'][0]['first_name']]);
        }
        if (($p = $by->get('new_photos')) && $p['uploaders']) {
            return __('Les photos de :names t’attendent.', ['names' => $this->joinNames($p['uploaders'])]);
        }

        return __('Les nouvelles de tes amis sur MemoryLane.');
    }
}
