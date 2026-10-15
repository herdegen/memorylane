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
        // Association automatique coupée par réglage (cf. config/memorylane.php).
        if (! config('memorylane.faces.auto_match')) {
            return null;
        }

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

        // Candidats jusqu'au seuil de SUGGESTION, pour connaître la 2e personne
        // la plus proche : l'association n'est faite que si la meilleure est
        // sous le seuil strict ET nettement devant la suivante (marge).
        $candidates = array_values(array_filter(
            $this->rankedCandidates($face, $this->matchThreshold(), $ownerId, $viewer),
            fn ($c) => ! in_array($c['person']['id'], $alreadyOnMedia, true),
        ));
        $best = $candidates[0] ?? null;

        if (! $best || $best['distance'] > $this->autoThreshold()) {
            return null;
        }
        if (isset($candidates[1]) && $candidates[1]['distance'] - $best['distance'] < $this->threshold('margin')) {
            return null;
        }

        // Garde-fou : un candidat douteux (bébé, parenté lointaine) n'est
        // jamais associé sans un humain.
        if ($best['doubts'] !== []) {
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
        $target = $this->embeddingOf($face);

        if ($target === null) {
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

        // « Ce n'est pas X » : jamais reproposé pour ce visage.
        foreach ($this->excludedPeople($face) as $personId) {
            unset($bestDistance[$personId]);
        }

        // « Qui était du voyage ? » : sur une photo d'un album dont on connaît
        // les participants, on ne propose qu'eux.
        $participants = app(AlbumParticipants::class)->forMediaId($face->media_id);
        if ($participants !== []) {
            $bestDistance = array_intersect_key($bestDistance, array_flip($participants));
        }

        if ($bestDistance === []) {
            return [];
        }

        // Fiches existantes (hors corbeille) et visibles du visiteur.
        $people = \App\Models\Person::query()
            ->whereIn('id', array_keys($bestDistance))
            ->when($viewer, fn ($q) => $q->visibleTo($viewer))
            ->get(['id', 'name', 'gender', 'birth_date', 'death_date', 'in_family_directory']);

        // Garde-fous : jamais une personne pas encore née (ou déjà décédée) à
        // la date de la photo ; les doutes (bébé, parenté lointaine) sont
        // signalés et légèrement pénalisés dans le classement.
        $media = $this->mediaOf($face);
        $plausibility = app(FacePlausibility::class);
        $kin = $media ? $plausibility->kinDistances($media, $face->person_id) : null;

        $bestByPerson = [];
        foreach ($people as $person) {
            if ($media && ($plausibility->impossibility($person, $media) || $plausibility->unlikelySuggestion($person, $media, $face->age_estimate))) {
                continue;
            }
            $distance = $bestDistance[$person->id];
            $bestByPerson[$person->id] = [
                'person' => ['id' => $person->id, 'name' => $person->name],
                'distance' => round($distance, 4),
                // Score de similarité (0..1) : 1 = identique, 0 = au seuil de suggestion.
                'score' => $this->score($distance),
                'doubts' => $media ? $plausibility->doubts($person, $media, $kin) : [],
            ];
        }

        $candidates = array_values($bestByPerson);
        $rank = fn ($c) => $c['distance'] + 0.05 * count($c['doubts']);
        usort($candidates, fn ($a, $b) => $rank($a) <=> $rank($b));

        return $candidates;
    }

    /**
     * Distance de l'identification actuelle du visage si la reconnaissance la
     * confirme, sinon null. Les autres visages de la même photo ne comptent
     * pas comme références (un nom posé plusieurs fois se confirmerait
     * lui-même) ; la personne doit être sous le seuil de suggestion et la plus
     * proche, ou à moins de la marge de la plus proche.
     */
    public function confirmation(DetectedFace $face, string $ownerId): ?float
    {
        $target = $this->embeddingOf($face);
        if ($target === null || $face->person_id === null) {
            return null;
        }
        $best = [];
        foreach ($this->references($ownerId) as [$faceId, $personId, $embedding, $mediaId]) {
            if ($mediaId === $face->media_id) {
                continue;
            }
            $distance = $this->euclideanDistance($target, $embedding);
            if (! isset($best[$personId]) || $distance < $best[$personId]) {
                $best[$personId] = $distance;
            }
        }
        $own = $best[$face->person_id] ?? null;
        if ($own === null || $own > $this->matchThreshold()) {
            return null;
        }

        return $own - min($best) <= $this->threshold('margin') ? $own : null;
    }

    /** Moteur de reconnaissance actif (config memorylane.faces.engine). */
    public function engine(): string
    {
        return config('memorylane.faces.engine', 'faceapi');
    }

    /** Colonne de l'empreinte du moteur actif. */
    public function embeddingColumn(): string
    {
        return $this->engine() === 'insightface' ? 'embedding_v2' : 'embedding';
    }

    /** Empreinte du visage pour le moteur actif (null si absente ou invalide). */
    public function embeddingOf(DetectedFace $face): ?array
    {
        $vector = $face->{$this->embeddingColumn()};
        $dims = $this->engine() === 'insightface' ? 512 : 128;

        return is_array($vector) && count($vector) === $dims ? $vector : null;
    }

    /** Seuil du moteur actif : 'match' (suggestion), 'auto', 'margin'. */
    public function threshold(string $name): float
    {
        return (float) config("memorylane.faces.thresholds.{$this->engine()}.{$name}");
    }

    /**
     * Ressemblance affichée (0..1) : 0 au seuil de suggestion ; 1 à distance
     * nulle (face-api) ou dès la distance « forte » (InsightFace : 50 % au
     * seuil d'association automatique).
     */
    public function score(float $distance): float
    {
        $match = $this->matchThreshold();
        $strong = (float) config("memorylane.faces.thresholds.{$this->engine()}.strong", 0.0);

        return round(max(0, min(1, ($match - $distance) / ($match - $strong))), 4);
    }

    public function matchThreshold(): float
    {
        return $this->threshold('match');
    }

    public function autoThreshold(): float
    {
        return $this->threshold('auto');
    }

    /** Mémo par requête (plusieurs visages d'une même photo, quêtes…). */
    private static array $referencesMemo = [];

    /**
     * Visages labellisés du propriétaire : [[face_id, person_id, embedding, media_id]…],
     * en cache jusqu'à la prochaine modification d'une identification
     * (version globale, cf. bumpReferences).
     *
     * @return array<int, array{0: string, 1: string, 2: array<int, float>, 3: string}>
     */
    /** Visages de référence du propriétaire (cf. references). */
    public function referencesFor(string $ownerId): array
    {
        return $this->references($ownerId);
    }

    private function references(string $ownerId): array
    {
        $version = (int) \Illuminate\Support\Facades\Cache::get('face-refs-version', 0);
        $engine = $this->engine();
        $key = "face-refs2:{$engine}:{$ownerId}:v{$version}";

        // Une seule entrée gardée en mémoire : les workers tournent longtemps,
        // les anciennes versions ne doivent pas s'accumuler.
        if (isset(self::$referencesMemo[$key])) {
            return self::$referencesMemo[$key];
        }

        return (self::$referencesMemo = [$key => \Illuminate\Support\Facades\Cache::remember($key, now()->addDay(), fn () => DetectedFace::query()
            ->whereNotNull('person_id')
            ->whereNotNull($this->embeddingColumn())
            ->whereHas('media', fn ($q) => $q->where('user_id', $ownerId))
            ->get(['id', 'media_id', 'person_id', 'embedding', 'embedding_v2'])
            ->map(fn (DetectedFace $f) => [$f->id, $f->person_id, $this->embeddingOf($f), $f->media_id])
            ->filter(fn ($row) => $row[2] !== null)
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
    public function applyMatch(DetectedFace $face, string $personId, bool $allowDuplicate = false, bool $verified = false): void
    {
        $person = \App\Models\Person::findOrFail($personId);
        $media = $this->mediaOf($face);

        // Garde-fous : personne née (et pas décédée) à la date de la photo, et
        // une seule fois par photo sauf confirmation (miroir, montage).
        app(FacePlausibility::class)->assertPossible($person, $media);
        if (! $allowDuplicate && DetectedFace::where('media_id', $face->media_id)
            ->where('person_id', $personId)->where('id', '!=', $face->id)->exists()) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'duplicate' => "{$person->name} est déjà identifié(e) sur un autre visage de cette photo.",
            ]);
        }

        $face->update([
            'person_id' => $personId,
            'status' => 'matched',
            // Posée par un humain (panneau, quête, revue), jamais par l'automatique.
            'verified_at' => $verified ? now() : null,
        ]);
        // Identifié comme X malgré un « ce n'est pas X » : l'exclusion tombe.
        \Illuminate\Support\Facades\DB::table('face_person_exclusions')
            ->where('detected_face_id', $face->id)->where('person_id', $personId)->delete();

        $media->people()->syncWithoutDetaching([
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
            'verified_at' => null,
        ]);

        if ($personId) {
            $this->reconcileMediaPersonPivot($this->mediaOf($face), $personId);
        }
    }

    /** Média du visage, y compris à la corbeille (la relation l'ignorerait). */
    private function mediaOf(DetectedFace $face): ?Media
    {
        return $face->media ?? Media::withTrashed()->find($face->media_id);
    }

    /**
     * Retire la personne du pivot media_person uniquement si aucun visage encore
     * associé à elle ne subsiste sur ce média.
     */
    public function reconcileMediaPersonPivot(Media $media, string $personId): void
    {
        $stillTagged = DetectedFace::query()
            ->where('media_id', $media->id)
            ->where('person_id', $personId)
            ->exists();

        // Seul un tag issu d'un visage (coordonnées) part avec lui ; un tag
        // posé à la main, importé ou « tag seul » (sans coordonnées) reste.
        if (! $stillTagged) {
            $media->people()->wherePivotNotNull('face_coordinates')->detach($personId);
        }
    }

    /**
     * « Ce n'est pas X » : le visage reste à identifier, mais le moteur ne
     * propose plus X pour lui. Aucune empreinte ni référence modifiée.
     */
    public function exclude(DetectedFace $face, string $personId, ?string $userId = null): void
    {
        \Illuminate\Support\Facades\DB::table('face_person_exclusions')->insertOrIgnore([
            'detected_face_id' => $face->id,
            'person_id' => $personId,
            'user_id' => $userId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        // Les analyses en cache (listes par personne, comptes) se recalculent.
        self::bumpReferences();
    }

    /** @return list<string> personnes exclues pour ce visage */
    public function excludedPeople(DetectedFace $face): array
    {
        return $face->exists
            ? \Illuminate\Support\Facades\DB::table('face_person_exclusions')->where('detected_face_id', $face->id)->pluck('person_id')->all()
            : [];
    }

    /**
     * « Tag seul » : c'est bien la personne, mais de dos, cachée, floue… Elle
     * reste sur la photo (tag sans coordonnées) ; le cadre est masqué et ne
     * sert plus de référence à la reconnaissance ni aux quêtes.
     */
    public function keepTagOnly(DetectedFace $face): void
    {
        $personId = $face->person_id;
        $media = $this->mediaOf($face);
        if ($personId && $media) {
            $media->people()->syncWithoutDetaching([$personId => ['face_coordinates' => null]]);
        }

        $face->update(['person_id' => null, 'status' => 'dismissed', 'verified_at' => null]);
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
