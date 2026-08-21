<?php

namespace App\Http\Controllers;

use App\Models\Media;
use App\Models\Person;
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
        protected MediaService $mediaService
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

        $memories = Media::with('conversions')
            ->where('user_id', auth()->id())
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

        // Anniversaires de naissance.
        $people = Person::whereNotNull('birth_date')
            ->whereNull('death_date')
            ->with('avatar.conversions')
            ->withMatchedFacesCount()
            ->get();

        foreach ($people as $person) {
            $days = $this->daysUntilAnniversary($person->birth_date, $today);
            if ($days === null) {
                continue;
            }
            $age = $today->copy()->addDays($days)->year - $person->birth_date->year;
            // Fiches généalogiques anciennes sans date de décès : au-delà d'un
            // âge plausible, on ne souhaite pas l'anniversaire.
            if ($age > 105) {
                continue;
            }
            $entries->push([
                'kind' => 'birthday',
                'emoji' => '🎂',
                'title' => $person->name,
                'sub' => "{$age} ans {$this->whenLabel($days)}",
                'days_until' => $days,
                'avatar_url' => $this->avatarUrl($person),
                'person_id' => $person->id,
            ]);
        }

        // Anniversaires de mariage / d'union.
        $unions = DB::table('person_relationships as r')
            ->join('people as p1', 'p1.id', '=', 'r.person1_id')
            ->join('people as p2', 'p2.id', '=', 'r.person2_id')
            ->whereNotNull('r.start_date')
            ->whereNull('r.end_date')
            ->whereNull('p1.death_date')
            ->whereNull('p2.death_date')
            ->get(['r.start_date', 'r.type', 'p1.id as p1_id', 'p1.name as p1_name', 'p2.name as p2_name']);

        foreach ($unions as $union) {
            $start = Carbon::parse($union->start_date)->startOfDay();
            $days = $this->daysUntilAnniversary($start, $today);
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

    /**
     * Jours restants avant la prochaine occurrence (mois + jour) de la date,
     * ou null hors de la fenêtre d'affichage.
     */
    private function daysUntilAnniversary(Carbon $date, Carbon $today): ?int
    {
        // Année courante d'abord (29 février : Carbon reporte au 1er mars).
        $next = $date->copy()->year($today->year)->startOfDay();
        if ($next->lt($today)) {
            $next->addYear();
        }

        $days = (int) $today->diffInDays($next);

        return $days <= self::CELEBRATIONS_WINDOW_DAYS ? $days : null;
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
        $ids = Person::has('media')->orderBy('id')->pluck('id');
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
            ->accessibleBy(auth()->user())
            ->where('type', 'photo')
            ->whereNotNull('taken_at')
            ->with('conversions')
            ->orderByDesc('taken_at')
            ->limit(4)
            ->get();
        $this->mediaService->hydrateSignedUrls($photos);

        $oldestYear = $person->media()
            ->accessibleBy(auth()->user())
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
