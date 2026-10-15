<?php

namespace App\Services\Vision;

use App\Models\DetectedFace;
use App\Models\Media;
use App\Models\Person;
use Illuminate\Support\Facades\Cache;

/**
 * Visages non identifiés susceptibles d'être une personne donnée (admin,
 * « Identifier par personne ») : distance au plus proche de ses visages déjà
 * identifiés (InsightFace), sous le seuil de suggestion, du plus ressemblant
 * au moins ressemblant. Exclus : photos où elle est déjà identifiée (une
 * seule fois par photo) et photos où elle ne peut pas figurer (pas née,
 * décédée). En cache jusqu'à la prochaine modification d'identification.
 *
 * Calcul global (toutes les personnes en une passe, ~30 s : tâche de fond
 * ComputePersonFaceCandidates) : pour la liste des personnes triée par
 * nombre de suggestions, et pour servir chaque personne instantanément.
 */
class PersonFaceCandidates
{
    /** Références de la personne (validées d'abord, puis les plus récentes). */
    private const MAX_REFERENCES = 40;

    public function __construct(private FaceMatcher $matcher) {}

    /**
     * @return array<string, float> id du visage => distance, triés
     */
    public function for(string $personId): array
    {
        $version = self::version();
        $all = Cache::get(self::ALL_KEY);
        $found = ($all['version'] ?? null) === $version
            ? ($all['byPerson'][$personId] ?? [])
            : Cache::remember("person-candidates:{$personId}:v{$version}", now()->addHour(), fn () => $this->compute($personId));

        // « Ce n'est pas elle » : retiré tout de suite, même d'un calcul en cache.
        $excluded = \Illuminate\Support\Facades\DB::table('face_person_exclusions')
            ->where('person_id', $personId)->pluck('detected_face_id')->flip()->all();

        return array_diff_key($found, $excluded);
    }

    public const ALL_KEY = 'person-candidates:all';

    private static function version(): int
    {
        return (int) Cache::get('face-refs-version', 0);
    }

    /**
     * Nombre de visages dont chaque personne est la suggestion du moteur (la
     * plus ressemblante), d'après le dernier calcul global — éventuellement
     * d'une version antérieure : un nouveau calcul est alors demandé.
     *
     * @return array{counts: array<string, int>, fresh: bool, ready: bool}
     */
    public function counts(): array
    {
        $all = Cache::get(self::ALL_KEY);
        $fresh = ($all['version'] ?? null) === self::version();
        if (! $fresh) {
            \App\Jobs\ComputePersonFaceCandidates::dispatch();
            // File synchrone (tests) : le résultat est déjà là.
            $all = Cache::get(self::ALL_KEY);
            $fresh = ($all['version'] ?? null) === self::version();
        }

        return ['counts' => $all['best'] ?? [], 'fresh' => $fresh, 'ready' => $all !== null];
    }

    /**
     * Toutes les personnes en une passe : distances (sous le seuil) de chaque
     * visage non identifié à chaque personne, et pour chaque visage la
     * personne la plus ressemblante.
     *
     * @return array{version: int, byPerson: array<string, array<string, float>>, best: array<string, int>}
     */
    public function computeAll(): array
    {
        $version = self::version();
        $references = [];
        $alreadyOn = [];
        DetectedFace::query()
            ->whereNotNull('person_id')
            ->whereHas('media')
            ->orderByRaw('verified_at is null')
            ->latest('updated_at')
            ->get(['person_id', 'media_id', 'embedding_v2'])
            ->each(function (DetectedFace $f) use (&$references, &$alreadyOn) {
                $alreadyOn[$f->person_id][$f->media_id] = true;
                $v = $f->embedding_v2;
                if (is_array($v) && count($v) === 512 && count($references[$f->person_id] ?? []) < self::MAX_REFERENCES) {
                    $references[$f->person_id][] = $v;
                }
            });
        $flat = [];
        foreach ($references as $personId => $vectors) {
            foreach ($vectors as $v) {
                $flat[] = [$personId, $v];
            }
        }
        $people = Person::whereIn('id', array_keys($references))->get()->keyBy('id');
        $excluded = [];
        foreach (\Illuminate\Support\Facades\DB::table('face_person_exclusions')->get(['detected_face_id', 'person_id']) as $row) {
            $excluded[$row->detected_face_id][$row->person_id] = true;
        }
        $plausibility = app(FacePlausibility::class);
        $maxSquared = $this->matcher->matchThreshold() ** 2;
        $byPerson = [];
        $best = [];

        DetectedFace::query()
            ->where('status', 'unmatched')
            ->whereNull('person_id')
            ->whereNotNull('embedding_v2')
            ->whereHas('media')
            ->with('media')
            ->select(['id', 'media_id', 'embedding_v2', 'age_estimate'])
            ->chunkById(300, function ($faces) use ($flat, $alreadyOn, $excluded, $people, $plausibility, $maxSquared, &$byPerson, &$best) {
                foreach ($faces as $face) {
                    $v = $face->embedding_v2;
                    if (! is_array($v) || count($v) !== 512) {
                        continue;
                    }
                    $nearest = [];
                    foreach ($flat as [$personId, $r]) {
                        $dot = 0.0;
                        for ($i = 0; $i < 512; $i++) {
                            $dot += $v[$i] * $r[$i];
                        }
                        $d2 = 2 - 2 * $dot;
                        if (! isset($nearest[$personId]) || $d2 < $nearest[$personId]) {
                            $nearest[$personId] = $d2;
                        }
                    }
                    $winner = null;
                    $winnerDistance = INF;
                    foreach ($nearest as $personId => $d2) {
                        if ($d2 > $maxSquared || isset($alreadyOn[$personId][$face->media_id]) || isset($excluded[$face->id][$personId])
                            || ! isset($people[$personId]) || $plausibility->impossibility($people[$personId], $face->media)
                            || $plausibility->unlikelySuggestion($people[$personId], $face->media, $face->age_estimate)) {
                            continue;
                        }
                        $distance = round(sqrt(max(0.0, $d2)), 4);
                        $byPerson[$personId][$face->id] = $distance;
                        if ($distance < $winnerDistance) {
                            [$winner, $winnerDistance] = [$personId, $distance];
                        }
                    }
                    if ($winner) {
                        $best[$winner] = ($best[$winner] ?? 0) + 1;
                    }
                }
            });
        foreach ($byPerson as &$faces) {
            asort($faces);
        }
        arsort($best);

        return ['version' => $version, 'byPerson' => $byPerson, 'best' => $best];
    }

    private function compute(string $personId): array
    {
        $person = Person::find($personId);
        if (! $person) {
            return [];
        }

        $references = DetectedFace::query()
            ->where('person_id', $personId)
            ->whereNotNull('embedding_v2')
            ->whereHas('media')
            ->orderByRaw('verified_at is null')
            ->latest('updated_at')
            ->limit(self::MAX_REFERENCES)
            ->pluck('embedding_v2')
            ->filter(fn ($v) => is_array($v) && count($v) === 512)
            ->values()
            ->all();
        if ($references === []) {
            return [];
        }

        $alreadyOn = DetectedFace::where('person_id', $personId)->pluck('media_id')->flip()->all();
        // Empreintes normées : distance² = 2 − 2 × produit scalaire.
        $maxSquared = $this->matcher->matchThreshold() ** 2;
        $found = [];

        DetectedFace::query()
            ->where('status', 'unmatched')
            ->whereNull('person_id')
            ->whereNotNull('embedding_v2')
            ->whereHas('media')
            ->select(['id', 'media_id', 'embedding_v2', 'age_estimate'])
            ->chunkById(500, function ($faces) use ($references, $alreadyOn, $maxSquared, &$found) {
                foreach ($faces as $face) {
                    $v = $face->embedding_v2;
                    if (isset($alreadyOn[$face->media_id]) || ! is_array($v) || count($v) !== 512) {
                        continue;
                    }
                    $best = INF;
                    foreach ($references as $r) {
                        $dot = 0.0;
                        for ($i = 0; $i < 512; $i++) {
                            $dot += $v[$i] * $r[$i];
                        }
                        $best = min($best, 2 - 2 * $dot);
                    }
                    if ($best <= $maxSquared) {
                        $found[$face->id] = [$face->media_id, sqrt(max(0.0, $best)), $face->age_estimate];
                    }
                }
            });

        // Garde-fous : jamais une photo où elle ne peut pas figurer, ni un
        // visage d'un âge sans rapport avec le sien à cette date.
        $plausibility = app(FacePlausibility::class);
        $medias = Media::whereIn('id', array_unique(array_column($found, 0)))->get()->keyBy('id');
        $result = [];
        foreach ($found as $faceId => [$mediaId, $distance, $faceAge]) {
            if (isset($medias[$mediaId]) && ! $plausibility->impossibility($person, $medias[$mediaId])
                && ! $plausibility->unlikelySuggestion($person, $medias[$mediaId], $faceAge)) {
                $result[$faceId] = round($distance, 4);
            }
        }
        asort($result);

        return $result;
    }
}
