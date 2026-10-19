<?php

namespace App\Services;

use App\Models\Album;
use App\Models\DetectedFace;
use App\Models\Household;
use App\Models\Invitation;
use App\Models\LifeEvent;
use App\Models\Media;
use App\Models\Person;
use App\Models\PersonLink;
use App\Models\User;
use App\Models\VoiceNote;
use App\Services\Vision\FaceMatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Départs : quitter un cercle (le compte reste) et quitter l'application.
 *
 * Principe : un compte s'en va, pas une personne — sa fiche reste dans
 * l'arbre. Ce qui change, c'est à qui appartiennent les choses et qui les
 * voit. Rien n'est retiré sans choix explicite ; ce qui reste passe à un
 * administrateur (ou au successeur désigné pour un groupe d'amis).
 */
class DepartureService
{
    /** Délai avant qu'une demande de départ de l'application soit appliquée. */
    public const GRACE_DAYS = 30;

    public function __construct(
        private FaceMatcher $faces,
        private InvitationService $invitations,
    ) {}

    // ------------------------------------------------------------------ cercle

    /**
     * Ce que le départ d'un cercle concerne, pour l'écran de confirmation.
     *
     * @return array{shared_photos: int, shared_albums: int, is_creator: bool, needs_successor: bool, last_member: bool, candidates: list<array{id: string, name: string}>}
     */
    public function circleSummary(User $user, Household $circle): array
    {
        $others = $circle->members()->where('users.id', '!=', $user->id)->whereNull('memorial_at')
            ->orderBy('name')->get(['users.id', 'users.name']);
        $isCreator = $circle->isFriends() && $circle->created_by === $user->id;

        return [
            'shared_photos' => DB::table('household_media')->join('media', 'media.id', '=', 'household_media.media_id')
                ->where('household_media.household_id', $circle->id)->where('media.user_id', $user->id)->whereNull('media.deleted_at')->count(),
            'shared_albums' => DB::table('album_household')->join('albums', 'albums.id', '=', 'album_household.album_id')
                ->where('album_household.household_id', $circle->id)->where('albums.user_id', $user->id)->whereNull('albums.deleted_at')->count(),
            'is_creator' => $isCreator,
            'needs_successor' => $isCreator && $others->isNotEmpty(),
            'last_member' => $others->isEmpty(),
            'candidates' => $others->map(fn ($u) => ['id' => $u->id, 'name' => $u->name])->values()->all(),
        ];
    }

    /**
     * Quitter un cercle. $photos : « keep » (restent partagés avec le cercle)
     * ou « withdraw » (photos et albums que J'AI partagés en sont retirés).
     */
    public function leaveCircle(User $user, Household $circle, string $photos, ?string $successorId = null): void
    {
        $summary = $this->circleSummary($user, $circle);
        if ($summary['needs_successor']) {
            if (! $successorId || ! collect($summary['candidates'])->contains('id', $successorId)) {
                throw ValidationException::withMessages(['successor_id' => 'Choisissez qui reprend ce groupe.']);
            }
        }

        DB::transaction(function () use ($user, $circle, $photos, $successorId, $summary) {
            if ($photos === 'withdraw') {
                $this->withdrawFromCircle($user, $circle);
            }

            if ($circle->isFriends()) {
                // Ma fiche sort du groupe (elle reste dans les autres cercles).
                if ($user->person_id) {
                    DB::table('household_person')->where('household_id', $circle->id)->where('person_id', $user->person_id)->delete();
                }
            } elseif ($user->person_id) {
                // Famille : la fiche reste dans l'arbre, l'adresse s'en va.
                Person::whereKey($user->person_id)->update(['address' => null, 'address_latitude' => null, 'address_longitude' => null]);
            }

            $circle->members()->detach($user->id);
            if ($user->current_household_id === $circle->id) {
                $user->forceFill(['current_household_id' => null])->save();
            }

            if ($summary['last_member']) {
                // Dernier membre : le groupe ferme (ses partages partent avec lui).
                if ($circle->isFriends()) {
                    $circle->delete();
                }
            } elseif ($summary['needs_successor']) {
                $circle->forceFill(['created_by' => $successorId])->save();
            }
        });
    }

    private function withdrawFromCircle(User $user, Household $circle): void
    {
        DB::table('household_media')->where('household_id', $circle->id)
            ->whereIn('media_id', Media::withTrashed()->where('user_id', $user->id)->select('id'))->delete();
        DB::table('album_household')->where('household_id', $circle->id)
            ->whereIn('album_id', Album::withTrashed()->where('user_id', $user->id)->select('id'))->delete();
    }

    // ------------------------------------------------------------ application

    /**
     * Ce que le compte laisse derrière lui, pour la page « Quitter MemoryLane ».
     */
    public function accountSummary(User $user): array
    {
        $mine = Media::where('user_id', $user->id);
        $sharedIds = $this->sharedMediaIds($user);

        return [
            'photos' => (clone $mine)->count(),
            'shared_photos' => count($sharedIds),
            'private_photos' => (clone $mine)->count() - count($sharedIds),
            'albums' => Album::where('user_id', $user->id)->count(),
            'fiches' => Person::where('user_id', $user->id)->count(),
            'moments' => LifeEvent::where('user_id', $user->id)->count(),
            'voice_notes' => VoiceNote::where('user_id', $user->id)->count(),
            'tagged_photos' => $user->person_id
                ? DB::table('media_person')->join('media', 'media.id', '=', 'media_person.media_id')
                    ->where('media_person.person_id', $user->person_id)->where('media.user_id', '!=', $user->id)->count()
                : 0,
            'created_circles' => Household::where('kind', Household::KIND_FRIENDS)->where('created_by', $user->id)
                ->with(['members' => fn ($q) => $q->where('users.id', '!=', $user->id)->whereNull('memorial_at')->orderBy('name')])
                ->get()
                ->map(fn (Household $h) => [
                    'id' => $h->id,
                    'name' => $h->name,
                    'candidates' => $h->members->map(fn ($u) => ['id' => $u->id, 'name' => $u->name])->values()->all(),
                ])->values()->all(),
            'is_last_admin' => $user->isAdmin() && $this->fallbackOwner($user) === null,
        ];
    }

    /** Programme le départ (appliqué après GRACE_DAYS, annulable). */
    public function requestAccountDeparture(User $user, array $options): void
    {
        if ($user->isAdmin() && $this->fallbackOwner($user) === null) {
            throw ValidationException::withMessages(['account' => 'Vous êtes le dernier administrateur : nommez-en un autre avant de partir.']);
        }
        $user->forceFill(['departure_requested_at' => now(), 'departure_options' => $options])->save();
    }

    public function cancelAccountDeparture(User $user): void
    {
        $user->forceFill(['departure_requested_at' => null, 'departure_options' => null])->save();
    }

    /**
     * Applique un départ de l'application : transferts, retraits selon les
     * choix, puis suppression du compte (la base refuse s'il possède encore
     * quelque chose).
     *
     * Options : photos = keep_shared (défaut) | keep_all | remove_all ;
     * voice_notes = keep | remove ; untag_me = bool ; successors = [household_id => user_id].
     */
    public function finalizeAccountDeparture(User $user): void
    {
        $options = (array) ($user->departure_options ?? []);
        $photos = $options['photos'] ?? 'keep_shared';
        $heir = $this->fallbackOwner($user);
        if (! $heir) {
            throw new \RuntimeException("Départ de {$user->id} impossible : aucun autre administrateur.");
        }

        $shared = array_flip($this->sharedMediaIds($user));
        $toDelete = [];

        DB::transaction(function () use ($user, $options, $photos, $heir, $shared, &$toDelete) {
            // Cercles d'amis créés : au successeur choisi, sinon à un autre membre, sinon fermés.
            foreach (Household::where('created_by', $user->id)->get() as $circle) {
                $successor = $options['successors'][$circle->id] ?? null;
                $successor = $circle->members()->where('users.id', '!=', $user->id)
                    ->when($successor, fn ($q) => $q->where('users.id', $successor))
                    ->orderBy('household_user.created_at')->value('users.id');
                if ($successor) {
                    $circle->forceFill(['created_by' => $successor])->save();
                } elseif ($circle->isFriends()) {
                    $circle->delete();
                } else {
                    $circle->forceFill(['created_by' => $heir->id])->save();
                }
            }

            // Photos et vidéos.
            foreach (Media::withTrashed()->where('user_id', $user->id)->get(['id', 'deleted_at']) as $media) {
                $keep = ! $media->trashed() && match ($photos) {
                    'keep_all' => true,
                    'remove_all' => false,
                    default => isset($shared[$media->id]),
                };
                if ($keep) {
                    Media::withTrashed()->whereKey($media->id)->update(['user_id' => $heir->id]);
                } else {
                    $toDelete[] = $media->id;
                }
            }

            // Albums : gardés (au même héritier) s'ils sont partagés ou s'il y reste des photos gardées.
            foreach (Album::withTrashed()->where('user_id', $user->id)->get() as $album) {
                $isShared = $album->households()->exists() || $album->accesses()->exists();
                $hasKept = $album->media()->whereNotIn('media.id', $toDelete)->exists();
                if (! $album->trashed() && ($photos === 'keep_all' || ($photos !== 'remove_all' && ($isShared || $hasKept)))) {
                    $album->forceFill(['user_id' => $heir->id])->saveQuietly();
                } else {
                    $album->forceDelete();
                }
            }

            // L'arbre et les histoires restent : fiches, moments, récits.
            Person::withTrashed()->where('user_id', $user->id)->update(['user_id' => $heir->id]);
            LifeEvent::where('user_id', $user->id)->update(['user_id' => $heir->id]);
            PersonLink::where('created_by', $user->id)->update(['created_by' => $heir->id]);

            // Souvenirs racontés (sa voix) : gardés sans auteur, ou retirés.
            if (($options['voice_notes'] ?? 'keep') === 'remove') {
                foreach (VoiceNote::where('user_id', $user->id)->get() as $note) {
                    $note->delete();
                }
            }

            // Son nom retiré des photos des autres (droit à l'image).
            if (! empty($options['untag_me']) && $user->person_id) {
                DetectedFace::where('person_id', $user->person_id)->get()
                    ->each(fn (DetectedFace $f) => $this->faces->disassociate($f));
                DB::table('media_person')->where('person_id', $user->person_id)->delete();
            }

            // Sa fiche reste dans l'arbre, sans son adresse.
            if ($user->person_id) {
                Person::whereKey($user->person_id)->update(['address' => null, 'address_latitude' => null, 'address_longitude' => null]);
            }

            // Ce qu'il avait lancé : invitations en attente révoquées.
            Invitation::open()->where('invited_by', $user->id)->get()->each(fn ($inv) => $this->invitations->revoke($inv));
            $user->pushSubscriptions()->delete();
        });

        // Suppressions définitives hors transaction (fichiers S3 purgés au forceDelete).
        foreach (array_chunk($toDelete, 100) as $chunk) {
            Media::withTrashed()->whereIn('id', $chunk)->get()->each->forceDelete();
        }

        $user->delete();
    }

    /** Administrateur qui hérite de ce qui reste (le plus ancien, autre que le partant). */
    public function fallbackOwner(User $user): ?User
    {
        return User::where('role', 'admin')->whereNull('memorial_at')->whereNull('departure_requested_at')
            ->where('id', '!=', $user->id)->orderBy('created_at')->first();
    }

    /**
     * Photos du compte que d'autres voient : partagées avec un cercle, dans
     * un album partagé avec un cercle ou avec un accès accordé, ou publiées
     * sur une fiche.
     *
     * @return list<string>
     */
    public function sharedMediaIds(User $user): array
    {
        return Media::where('user_id', $user->id)
            ->where(fn ($q) => $q
                ->whereExists(fn ($s) => $s->selectRaw('1')->from('household_media')->whereColumn('household_media.media_id', 'media.id'))
                ->orWhereExists(fn ($s) => $s->selectRaw('1')->from('album_media')
                    ->whereColumn('album_media.media_id', 'media.id')
                    ->where(fn ($a) => $a
                        ->whereExists(fn ($h) => $h->selectRaw('1')->from('album_household')->whereColumn('album_household.album_id', 'album_media.album_id'))
                        ->orWhereExists(fn ($h) => $h->selectRaw('1')->from('album_access')->whereColumn('album_access.album_id', 'album_media.album_id'))))
                ->orWhereExists(fn ($s) => $s->selectRaw('1')->from('media_person')
                    ->whereColumn('media_person.media_id', 'media.id')
                    ->where('media_person.fiche_status', FichePublisher::PUBLISHED)))
            ->pluck('id')->all();
    }
}
