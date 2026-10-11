<?php

namespace App\Services\Vision;

use App\Models\DetectedFace;
use App\Models\Media;

/**
 * Reconnaissance des personnes à partir des descripteurs faciaux (face-api.js,
 * 128 floats, distance euclidienne native).
 *
 * Logique serveur PURE, scopée par PROPRIÉTAIRE du média : elle ne dépend pas
 * de l'utilisateur authentifié. C'est indispensable pour la relancer depuis
 * l'admin (Filament), où l'acteur n'est pas le propriétaire des médias.
 */
class FaceMatcher
{
    /**
     * Distance euclidienne maximale entre deux descripteurs pour considérer
     * qu'il s'agit de la même personne (suggestion).
     */
    public const MATCH_THRESHOLD = 0.6;

    /**
     * Seuil (plus strict) au-dessous duquel on associe AUTOMATIQUEMENT la
     * personne, sans confirmation (auto-association en tâche de fond).
     */
    public const AUTO_MATCH_THRESHOLD = 0.45;

    /**
     * Statut « collant » d'un visage explicitement désassocié par un humain :
     * exclu de l'auto-association (autoMatch ne traite que `unmatched`) et retiré
     * du jeu de références (person_id vidé). Empêche qu'une correction humaine
     * soit défaite par le re-matching automatique (issue #30).
     */
    public const REJECTED_STATUS = 'rejected';

    /**
     * Auto-association d'un visage : associe le plus proche voisin labellisé
     * (parmi les visages du même propriétaire) UNIQUEMENT si la distance est
     * sous le seuil strict. Retourne l'entrée candidate retenue, ou null.
     *
     * @return array{person: array{id: string, name: string}, distance: float, score: float}|null
     */
    public function autoMatch(DetectedFace $face, string $ownerId, ?\App\Models\User $viewer = null): ?array
    {
        // Ne jamais écraser une association existante.
        if ($face->person_id || $face->status !== 'unmatched') {
            return null;
        }

        // Une personne ne peut pas être auto-associée deux fois sur le même
        // média : hors montage/reflet (rares, laissés à l'association manuelle),
        // une personne n'apparaît qu'une fois par photo. On écarte donc les
        // personnes déjà associées à un autre visage du média et on retient le
        // meilleur candidat encore disponible.
        $alreadyOnMedia = DetectedFace::query()
            ->where('media_id', $face->media_id)
            ->whereNotNull('person_id')
            ->where('id', '!=', $face->id)
            ->pluck('person_id')
            ->all();

        $best = null;
        foreach ($this->rankedCandidates($face, self::AUTO_MATCH_THRESHOLD, $ownerId, $viewer) as $candidate) {
            if (! in_array($candidate['person']['id'], $alreadyOnMedia, true)) {
                $best = $candidate;
                break;
            }
        }

        if (! $best) {
            return null;
        }

        $this->applyMatch($face, $best['person']['id']);

        return $best;
    }

    /**
     * Personnes candidates (plus proche voisin par personne) sous $maxDistance,
     * triées par distance croissante. Chaque entrée :
     * {person{id,name}, distance, score}. Les candidats sont restreints aux
     * visages labellisés appartenant au propriétaire $ownerId. Avec $viewer,
     * seules les personnes qu'il peut voir sont proposées (pas de fiche d'un
     * autre cercle).
     *
     * @return array<int, array{person: array{id: string, name: string}, distance: float, score: float}>
     */
    public function rankedCandidates(DetectedFace $face, float $maxDistance, string $ownerId, ?\App\Models\User $viewer = null): array
    {
        $target = $face->embedding;

        if (! is_array($target) || count($target) !== 128) {
            return [];
        }

        // Références (visages labellisés du propriétaire) lues depuis le cache
        // versionné, puis comparaison en mémoire : plus de relecture/décodage
        // des ~2 000 empreintes en base à chaque visage analysé.
        $bestDistance = [];
        foreach ($this->references($ownerId) as [$faceId, $personId, $embedding]) {
            if ($faceId === $face->id) {
                continue;
            }
            $distance = $this->euclideanDistance($target, $embedding);
            if ($distance <= $maxDistance && (! isset($bestDistance[$personId]) || $distance < $bestDistance[$personId])) {
                $bestDistance[$personId] = $distance;
            }
        }

        if ($bestDistance === []) {
            return [];
        }

        // Fiches existantes (hors corbeille) et visibles du visiteur.
        $names = \App\Models\Person::query()
            ->whereIn('id', array_keys($bestDistance))
            ->when($viewer, fn ($q) => $q->visibleTo($viewer))
            ->pluck('name', 'id');

        $bestByPerson = [];
        foreach ($names as $personId => $name) {
            $distance = $bestDistance[$personId];
            $bestByPerson[$personId] = [
                'person' => ['id' => $personId, 'name' => $name],
                'distance' => round($distance, 4),
                // Score de similarité (0..1) : 1 = identique, 0 = au seuil de suggestion.
                'score' => round(max(0, 1 - $distance / self::MATCH_THRESHOLD), 4),
            ];
        }

        $candidates = array_values($bestByPerson);
        usort($candidates, fn ($a, $b) => $a['distance'] <=> $b['distance']);

        return $candidates;
    }

    /** Mémo par requête (plusieurs visages d'une même photo, quêtes…). */
    private static array $referencesMemo = [];

    /**
     * Visages labellisés du propriétaire : [[face_id, person_id, embedding]…],
     * en cache jusqu'à la prochaine modification d'une identification
     * (version globale, cf. bumpReferences).
     *
     * @return array<int, array{0: string, 1: string, 2: array<int, float>}>
     */
    private function references(string $ownerId): array
    {
        $version = (int) \Illuminate\Support\Facades\Cache::get('face-refs-version', 0);
        $key = "face-refs:{$ownerId}:v{$version}";

        // Une seule entrée gardée en mémoire : les workers tournent longtemps,
        // les anciennes versions ne doivent pas s'accumuler.
        if (isset(self::$referencesMemo[$key])) {
            return self::$referencesMemo[$key];
        }

        return (self::$referencesMemo = [$key => \Illuminate\Support\Facades\Cache::remember($key, now()->addDay(), fn () => DetectedFace::query()
            ->whereNotNull('person_id')
            ->whereNotNull('embedding')
            ->whereHas('media', fn ($q) => $q->where('user_id', $ownerId))
            ->get(['id', 'person_id', 'embedding'])
            ->filter(fn (DetectedFace $f) => is_array($f->embedding) && count($f->embedding) === 128)
            ->map(fn (DetectedFace $f) => [$f->id, $f->person_id, $f->embedding])
            ->values()
            ->all())])[$key];
    }

    /**
     * Invalide les références en cache (une identification a changé).
     */
    public static function bumpReferences(): void
    {
        \Illuminate\Support\Facades\Cache::forever('face-refs-version', (int) \Illuminate\Support\Facades\Cache::get('face-refs-version', 0) + 1);
        self::$referencesMemo = [];
    }

    /**
     * Associe un visage à une personne (statut matched + pivot media_person).
     */
    public function applyMatch(DetectedFace $face, string $personId): void
    {
        $face->update([
            'person_id' => $personId,
            'status' => 'matched',
        ]);

        $face->media->people()->syncWithoutDetaching([
            $personId => [
                'face_coordinates' => json_encode($face->bounding_box),
            ],
        ]);
    }

    /**
     * Désassocie « collant » un visage de sa personne : vide `person_id`, passe
     * le visage dans un statut terminal (`rejected` par défaut, ou `dismissed`)
     * qui l'exclut de l'auto-association, et retire la personne du pivot
     * media_person SEULEMENT si plus aucun autre visage d'elle ne reste sur ce
     * média (le pivot est per-média, pas per-visage). Corrige une association
     * erronée sans qu'elle revienne via le re-matching (issue #30).
     */
    public function disassociate(DetectedFace $face, string $status = self::REJECTED_STATUS): void
    {
        $personId = $face->person_id;

        $face->update([
            'person_id' => null,
            'status' => $status,
        ]);

        if ($personId) {
            $this->reconcileMediaPersonPivot($face->media, $personId);
        }
    }

    /**
     * Retire la personne du pivot media_person uniquement si aucun visage encore
     * associé à elle ne subsiste sur ce média.
     */
    private function reconcileMediaPersonPivot(Media $media, string $personId): void
    {
        $stillTagged = DetectedFace::query()
            ->where('media_id', $media->id)
            ->where('person_id', $personId)
            ->exists();

        if (! $stillTagged) {
            $media->people()->detach($personId);
        }
    }

    public function euclideanDistance(array $a, array $b): float
    {
        $sum = 0.0;
        foreach ($a as $i => $v) {
            $d = $v - ($b[$i] ?? 0);
            $sum += $d * $d;
        }

        return sqrt($sum);
    }
}
