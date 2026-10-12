<?php

namespace App\Http\Controllers;

use App\Models\Media;
use App\Models\Person;
use App\Services\CelebrationService;
use App\Services\MediaService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class DashboardController extends Controller
{
    /** Fenêtre (en jours) des fêtes & anniversaires à venir affichés. */
    private const CELEBRATIONS_WINDOW_DAYS = 15;

    public function __construct(
        protected MediaService $mediaService,
        protected CelebrationService $celebrations,
    ) {}

    public function index()
    {
        $onThisDay = $this->onThisDay();

        return Inertia::render('Dashboard', [
            'onThisDay' => $onThisDay,
            'celebrations' => $this->celebrations(),
            // La personne du jour est le REPLI quand aucun souvenir daté du jour.
            'personOfTheDay' => $onThisDay->isEmpty() ? $this->personOfTheDay() : null,
            'showGuide' => auth()->user()->dashboard_guide_hidden_at === null,
        ]);
    }

    /**
     * Masque le bloc « Bien démarrer » pour ce compte (définitif, par personne).
     */
    public function hideGuide(Request $request)
    {
        $request->user()->forceFill(['dashboard_guide_hidden_at' => now()])->save();

        return response()->json(['message' => 'Guide masqué.']);
    }

    /**
     * « Ce jour-là » : les médias pris un même jour (mois + jour) les années
     * précédentes, groupés par année, de la plus récente à la plus ancienne.
     */
    protected function onThisDay()
    {
        $today = now();

        // Famille : ses propres photos ; cercle d'amis : les photos du cercle
        // (y compris celles des autres membres).
        $user = auth()->user();
        $memories = Media::with('conversions')
            ->when(
                $user->mode() === \App\Models\Household::KIND_FRIENDS,
                fn ($q) => $q->inContext($user),
                fn ($q) => $q->where('user_id', $user->id),
            )
            ->whereNotNull('taken_at')
            ->whereRaw('EXTRACT(MONTH FROM taken_at) = ?', [$today->month])
            ->whereRaw('EXTRACT(DAY FROM taken_at) = ?', [$today->day])
            ->whereRaw('EXTRACT(YEAR FROM taken_at) < ?', [$today->year])
            ->orderByDesc('taken_at')
            ->limit(48)
            ->get();

        $this->mediaService->hydrateSignedUrls($memories);

        return $memories
            ->groupBy(fn ($media) => $media->taken_at->year)
            ->map(fn ($group, $year) => [
                'year'      => (int) $year,
                'years_ago' => $today->year - (int) $year,
                'media'     => $group->take(8)->values(),
            ])
            ->sortByDesc('year')
            ->values();
    }

    /**
     * Fêtes & anniversaires dans les 15 prochains jours : anniversaires de
     * naissance (personnes vivantes) et anniversaires de mariage/union
     * (unions non terminées, dates portées par person_relationships).
     */
    protected function celebrations()
    {
        $today = now()->startOfDay();
        $entries = collect();

        // Anniversaires de naissance (logique partagée avec le push quotidien).
        $birthdays = $this->celebrations->upcomingBirthdays(
            $today,
            self::CELEBRATIONS_WINDOW_DAYS,
            Person::inContext(auth()->user()),
        );
        $this->loadAvatars($birthdays->pluck('person'));

        foreach ($birthdays as ['person' => $person, 'age' => $age, 'days_until' => $days]) {
            $entries->push([
                'kind' => 'birthday',
                'emoji' => '🎂',
                'title' => $person->name,
                // Entre amis, pas d'âge : juste le jour.
                'sub' => auth()->user()->mode() === \App\Models\Household::KIND_FRIENDS
                    ? ucfirst($this->whenLabel($days))
                    : "{$age} ans {$this->whenLabel($days)}",
                'days_until' => $days,
                'avatar_url' => $this->avatarUrl($person),
                'person_id' => $person->id,
            ]);
        }

        // Anniversaires de décès : uniquement le cercle proche du visiteur
        // (un proche disparu, pas un ancêtre lointain), ton sobre.
        $memorials = $this->celebrations->upcomingMemorials(
            $today,
            self::CELEBRATIONS_WINDOW_DAYS,
            $this->celebrations->closeCircleQuery(auth()->user()),
        );
        $this->loadAvatars($memorials->pluck('person'));

        foreach ($memorials as ['person' => $person, 'years' => $years, 'days_until' => $days]) {
            $entries->push([
                'kind' => 'memorial',
                'emoji' => '🕯️',
                'title' => $person->name,
                'sub' => 'En mémoire · ' . self::yearsLabel($years) . " {$this->whenLabel($days)}",
                'days_until' => $days,
                'avatar_url' => $this->avatarUrl($person),
                'person_id' => $person->id,
            ]);
        }

        // Fêtes des prénoms du jour (calendrier Nominis, cache local) :
        // uniquement le jour J, pour les personnes vivantes dont le prénom
        // correspond (accents/casse ignorés).
        $names = app(\App\Services\NameDayService::class)->namesFor($today);
        if ($names !== []) {
            $celebrated = Person::inContext(auth()->user())->whereNull('death_date')
                ->get(['people.id', 'people.name', 'people.first_name', 'people.avatar_media_id'])
                ->filter(function (Person $person) use ($names) {
                    $first = $person->first_name ?: strtok($person->name, ' ');
                    return $first && in_array(\App\Services\NameDayService::normalize($first), $names, true);
                });
            $this->loadAvatars($celebrated);

            foreach ($celebrated as $person) {
                $first = $person->first_name ?: strtok($person->name, ' ');
                $entries->push([
                    'kind' => 'nameday',
                    'emoji' => '🥳',
                    'title' => $person->name,
                    'sub' => "Bonne fête {$first} !",
                    'days_until' => 0,
                    'avatar_url' => $this->avatarUrl($person),
                    'person_id' => $person->id,
                ]);
            }
        }

        // Anniversaires de mariage / d'union.
        $unions = DB::table('person_relationships as r')
            ->join('people as p1', 'p1.id', '=', 'r.person1_id')
            ->join('people as p2', 'p2.id', '=', 'r.person2_id')
            ->whereIn('r.person1_id', Person::inContext(auth()->user())->select('people.id'))
            ->whereIn('r.person2_id', Person::inContext(auth()->user())->select('people.id'))
            ->whereNotNull('r.start_date')
            ->whereNull('r.end_date')
            ->whereNull('p1.death_date')
            ->whereNull('p2.death_date')
            ->get(['r.start_date', 'r.type', 'p1.id as p1_id', 'p1.name as p1_name', 'p2.name as p2_name']);

        foreach ($unions as $union) {
            $start = Carbon::parse($union->start_date)->startOfDay();
            $days = CelebrationService::daysUntilAnniversary($start, $today, self::CELEBRATIONS_WINDOW_DAYS);
            if ($days === null) {
                continue;
            }
            $years = $today->copy()->addDays($days)->year - $start->year;
            $word = $union->type === 'partner' ? "d'union" : 'de mariage';
            $entries->push([
                'kind' => 'wedding',
                'emoji' => '💍',
                'title' => "{$union->p1_name} & {$union->p2_name}",
                'sub' => "{$years} ans {$word} {$this->whenLabel($days)}",
                'days_until' => $days,
                'avatar_url' => null,
                'person_id' => $union->p1_id,
            ]);
        }

        return $entries->sortBy('days_until')->values()->take(8);
    }

    private static function yearsLabel(int $years): string
    {
        return $years === 1 ? '1 an' : "{$years} ans";
    }

    private function whenLabel(int $days): string
    {
        return match (true) {
            $days === 0 => "aujourd'hui",
            $days === 1 => 'demain',
            default => "dans {$days} jours",
        };
    }

    /**
     * Personne du jour (repli sans souvenir daté) : tirage déterministe par
     * date parmi les personnes ayant des médias — la même pour tout le monde,
     * toute la journée.
     */
    protected function personOfTheDay(): ?array
    {
        $ids = Person::inContext(auth()->user())->has('media')->orderBy('id')->pluck('id');
        if ($ids->isEmpty()) {
            return null;
        }

        $pick = $ids[crc32(now()->toDateString()) % $ids->count()];

        $person = Person::with('avatar.conversions')
            ->withCount('media')
            ->withMatchedFacesCount()
            ->find($pick);

        // Photos visibles par le visiteur : la plus ancienne date l'accroche,
        // les 4 plus récentes font la bande de vignettes.
        $photos = $person->media()
            ->inContext(auth()->user())
            ->where('type', 'photo')
            ->whereNotNull('taken_at')
            ->with('conversions')
            ->orderByDesc('taken_at')
            ->limit(4)
            ->get();
        $this->mediaService->hydrateSignedUrls($photos);

        $oldestYear = $person->media()
            ->inContext(auth()->user())
            ->whereNotNull('taken_at')
            ->min('taken_at');

        return [
            'id' => $person->id,
            'name' => $person->name,
            'avatar_url' => $this->avatarUrl($person),
            'media_count' => $person->media_count,
            'oldest_year' => $oldestYear ? Carbon::parse($oldestYear)->year : null,
            'photos' => $photos,
        ];
    }

    /**
     * Avatar d'une personne : photo de profil explicite, sinon recadrage du
     * visage tagué (miroir de PersonController::resolveAvatarUrl).
     */
    /**
     * Charge de quoi calculer l'avatar, seulement pour les quelques fiches
     * retenues (et non pour tout le répertoire parcouru).
     */
    private function loadAvatars(\Illuminate\Support\Collection $people): void
    {
        if ($people->isNotEmpty()) {
            (new \Illuminate\Database\Eloquent\Collection($people->all()))
                ->load('avatar:id')
                ->loadCount(Person::matchedFacesCountRelation());
        }
    }

    private function avatarUrl(Person $person): ?string
    {
        if ($person->avatar) {
            return route('people.avatarImage', $person);
        }

        if (($person->matched_faces_count ?? 0) > 0) {
            return url("/people/{$person->id}/face-avatar");
        }

        return null;
    }
}
