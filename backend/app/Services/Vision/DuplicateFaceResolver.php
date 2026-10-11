<?php

namespace App\Services\Vision;

use App\Models\DetectedFace;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Doublons d'identification : la même personne associée à plusieurs visages
 * d'une même photo (hors miroir ou montage, c'est une erreur — typiquement
 * l'auto-association sur les petits visages d'une photo de groupe).
 *
 * Pour chaque groupe (photo, personne), chaque visage est comparé aux AUTRES
 * photos de la personne (moyenne des 5 empreintes les plus proches) : le plus
 * ressemblant est suggéré. La suggestion est « nette » quand il devance le
 * suivant d'au moins CLEAR_GAP ; sinon un humain tranche.
 */
class DuplicateFaceResolver
{
    public const CLEAR_GAP = 0.05;

    /** @var array<string, array{group: string, score: ?float, suggested: bool, clear: bool}>|null */
    private ?array $analysis = null;

    public function __construct(private FaceMatcher $matcher) {}

    /** Paires (photo, personne) identifiées sur plus d'un visage. */
    public function pairs(): Collection
    {
        return DB::table('detected_faces')
            ->whereNotNull('person_id')->where('status', 'matched')
            ->select('media_id', 'person_id')
            ->groupBy('media_id', 'person_id')
            ->havingRaw('count(*) > 1')
            ->get();
    }

    /**
     * Analyse de tous les visages en double, indexée par id de visage.
     *
     * @return array<string, array{group: string, score: ?float, suggested: bool, clear: bool}>
     */
    public function analysis(): array
    {
        // Gardée en cache jusqu'à la prochaine modification d'une
        // identification (même version que les références de FaceMatcher).
        $version = (int) Cache::get('face-refs-version', 0);

        return $this->analysis ??= Cache::remember("face-duplicates:v{$version}", now()->addHour(), fn () => $this->compute());
    }

    private function compute(): array
    {
        $result = [];
        foreach ($this->pairs()->groupBy('person_id') as $personId => $pairs) {
            // Toutes les empreintes de la personne, chargées une seule fois.
            $faces = DetectedFace::where('person_id', $personId)->whereNotNull('embedding')
                ->get(['id', 'media_id', 'embedding', 'status'])
                ->filter(fn ($f) => is_array($f->embedding) && count($f->embedding) === 128);

            foreach ($pairs as $pair) {
                $refs = $faces->where('media_id', '!=', $pair->media_id)->pluck('embedding');
                $scored = $faces->where('media_id', $pair->media_id)->where('status', 'matched')
                    ->map(fn (DetectedFace $f) => [
                        'id' => $f->id,
                        'score' => $refs->isNotEmpty()
                            ? round($refs->map(fn ($r) => $this->matcher->euclideanDistance($f->embedding, $r))->sort()->take(5)->avg(), 3)
                            : null,
                    ])
                    ->sortBy(fn ($x) => $x['score'] ?? INF)->values();

                $clear = $scored->count() > 1 && $scored[0]['score'] !== null && $scored[1]['score'] !== null
                    && ($scored[1]['score'] - $scored[0]['score']) >= self::CLEAR_GAP;

                foreach ($scored as $i => $face) {
                    $result[$face['id']] = [
                        'group' => $pair->media_id.':'.$pair->person_id,
                        'score' => $face['score'],
                        'suggested' => $i === 0 && $face['score'] !== null,
                        'clear' => $clear,
                    ];
                }
            }
        }

        return $result;
    }

    /**
     * Garde ce visage pour sa personne sur la photo ; les autres visages
     * associés à la même personne redeviennent « à identifier ».
     */
    public function keep(DetectedFace $face): int
    {
        $others = DetectedFace::where('media_id', $face->media_id)
            ->where('person_id', $face->person_id)
            ->where('id', '!=', $face->id)->get();

        foreach ($others as $other) {
            $other->update(['person_id' => null, 'status' => 'unmatched']);
        }
        $this->analysis = null;

        return $others->count();
    }

    /**
     * Applique toutes les suggestions nettes.
     *
     * @return array{groups: int, faces: int}
     */
    public function applyClearSuggestions(): array
    {
        $groups = 0;
        $faces = 0;
        foreach ($this->analysis() as $faceId => $row) {
            if ($row['suggested'] && $row['clear'] && ($face = DetectedFace::find($faceId))) {
                $faces += $this->keep($face);
                $groups++;
            }
        }

        return ['groups' => $groups, 'faces' => $faces];
    }
}
