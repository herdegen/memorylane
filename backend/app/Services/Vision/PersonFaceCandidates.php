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
        $version = (int) Cache::get('face-refs-version', 0);

        return Cache::remember("person-candidates:{$personId}:v{$version}", now()->addHour(), fn () => $this->compute($personId));
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
            ->select(['id', 'media_id', 'embedding_v2'])
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
                        $found[$face->id] = [$face->media_id, sqrt(max(0.0, $best))];
                    }
                }
            });

        // Garde-fou de date : jamais une photo où elle ne peut pas figurer.
        $plausibility = app(FacePlausibility::class);
        $medias = Media::whereIn('id', array_unique(array_column($found, 0)))->get()->keyBy('id');
        $result = [];
        foreach ($found as $faceId => [$mediaId, $distance]) {
            if (isset($medias[$mediaId]) && ! $plausibility->impossibility($person, $medias[$mediaId])) {
                $result[$faceId] = round($distance, 4);
            }
        }
        asort($result);

        return $result;
    }
}
