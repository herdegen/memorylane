<?php

namespace App\Services\Vision;

use App\Models\Album;
use App\Models\DetectedFace;
use App\Models\Media;
use App\Models\Person;
use Illuminate\Support\Facades\DB;

/**
 * « Qui était du voyage ? » : les participants d'un album.
 *
 * - Suggestions de visages limitées aux participants (FaceMatcher).
 * - Un visage non identifié qui ne ressemble à AUCUN participant (distance
 *   au-delà du seuil de suggestion) est rangé en « inconnu » — marqué par
 *   auto_rejected_album_id, donc annulable. Jamais d'identification
 *   automatique ici : on ne fait qu'écarter des inconnus.
 * - Prudence : tant qu'un participant n'a aucun visage de référence, on ne
 *   peut pas affirmer qu'un visage n'est pas lui → rien n'est écarté.
 */
class AlbumParticipants
{
    /** @var array<string, list<string>> */
    private array $memo = [];

    public function __construct(private FaceMatcher $matcher) {}

    /**
     * Participants (ids) des albums contenant ce média ; [] si aucun album
     * du média n'a de participants.
     *
     * @return list<string>
     */
    public function forMediaId(string $mediaId): array
    {
        return $this->memo[$mediaId] ??= DB::table('album_media as am')
            ->join('album_participants as ap', 'ap.album_id', '=', 'am.album_id')
            ->where('am.media_id', $mediaId)
            ->distinct()
            ->pluck('ap.person_id')
            ->all();
    }

    /**
     * Range en « inconnu » le visage s'il ne ressemble à aucun participant.
     * Renvoie true s'il a été écarté.
     */
    public function classify(DetectedFace $face): bool
    {
        if ($face->person_id || $face->status !== 'unmatched') {
            return false;
        }

        $media = $face->media;
        if (! $media || ($participants = $this->forMediaId($media->id)) === []) {
            return false;
        }

        $target = $this->matcher->embeddingOf($face);
        if ($target === null) {
            return false;
        }

        $refs = array_values(array_filter(
            $this->matcher->referencesFor($media->user_id),
            fn ($row) => in_array($row[1], $participants, true) && $row[0] !== $face->id,
        ));
        // Un participant sans référence pourrait être n'importe quel visage.
        if (count(array_unique(array_column($refs, 1))) < count($participants)) {
            return false;
        }

        foreach ($refs as [, , $embedding]) {
            if ($this->matcher->euclideanDistance($target, $embedding) <= $this->matcher->matchThreshold()) {
                return false; // ressemble à un participant : on demandera
            }
        }

        $albumId = DB::table('album_media as am')
            ->join('album_participants as ap', 'ap.album_id', '=', 'am.album_id')
            ->where('am.media_id', $media->id)
            ->value('am.album_id');

        $face->update(['status' => FaceMatcher::REJECTED_STATUS, 'auto_rejected_album_id' => $albumId]);

        return true;
    }

    /**
     * Applique les participants à tout l'album : annule d'abord les rejets
     * automatiques précédents, puis re-classe les visages non identifiés.
     *
     * @return array{rejected: int, to_identify: int, missing_refs: list<string>}
     */
    public function classifyAlbum(Album $album): array
    {
        $this->memo = [];
        $this->revert($album);

        $participants = $album->participants()->get(['people.id', 'people.name']);
        $missing = [];
        if ($participants->isNotEmpty()) {
            $owners = $album->media()->reorder()->distinct()->pluck('media.user_id');
            $refPeople = collect($owners)
                ->flatMap(fn ($owner) => array_column($this->matcher->referencesFor($owner), 1))
                ->unique()
                ->flip();
            $missing = $participants->reject(fn (Person $p) => isset($refPeople[$p->id]))->pluck('name')->values()->all();
        }

        $rejected = 0;
        $toIdentify = 0;
        $faces = DetectedFace::whereIn('media_id', $album->media()->pluck('media.id'))
            ->where('status', 'unmatched')
            ->whereNull('person_id')
            ->with('media')
            ->get();
        foreach ($faces as $face) {
            $this->classify($face) ? $rejected++ : $toIdentify++;
        }

        return ['rejected' => $rejected, 'to_identify' => $toIdentify, 'missing_refs' => $missing];
    }

    /** Annule les rejets automatiques dus à cet album. */
    public function revert(Album $album): int
    {
        return DetectedFace::where('auto_rejected_album_id', $album->id)
            ->whereNull('person_id')
            ->where('status', FaceMatcher::REJECTED_STATUS)
            ->update(['status' => 'unmatched', 'auto_rejected_album_id' => null]);
    }

    /** Visages d'un média rangés en inconnu après une nouvelle détection. */
    public function classifyMedia(Media $media): void
    {
        if ($this->forMediaId($media->id) === []) {
            return;
        }
        foreach ($media->detectedFaces()->where('status', 'unmatched')->whereNull('person_id')->get() as $face) {
            $face->setRelation('media', $media);
            $this->classify($face);
        }
    }

    /**
     * Personnes déjà identifiées sur les photos des albums (visibles du compte)
     * de ce média, hors celles déjà sur la photo : présentes à l'événement,
     * proposées même si leur fiche est d'un autre cercle.
     *
     * @return \Illuminate\Support\Collection<int, array{id: string, name: string}>
     */
    public function identifiedAround(\App\Models\Media $media, \App\Models\User $user)
    {
        $albumIds = $media->albums()->accessibleBy($user)->pluck('albums.id');
        if ($albumIds->isEmpty()) {
            return collect();
        }
        $onPhoto = $media->people()->pluck('people.id');

        return \App\Models\Person::visibleTo($user)
            ->whereHas('media', fn ($m) => $m->whereHas('albums', fn ($a) => $a->whereIn('albums.id', $albumIds)))
            ->whereNotIn('people.id', $onPhoto)
            ->orderBy('name')
            ->limit(60)
            ->get(['people.id', 'people.name'])
            ->map(fn (\App\Models\Person $p) => ['id' => $p->id, 'name' => $p->name]);
    }
}
