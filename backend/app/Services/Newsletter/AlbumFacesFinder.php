<?php

namespace App\Services\Newsletter;

use App\Models\Album;
use App\Models\User;
use App\Services\QuestService;

/**
 * Encart « Il y a X visages non identifiés dans l'album Y, où tu
 * apparais » des lettres du dimanche (famille et amis).
 *
 * Album éligible : le destinataire peut le voir, il est de SON monde (le
 * critère est donné par la lettre : albums de la famille, ou partagés avec
 * tel cercle d'amis), sa fiche y est identifiée sur au moins une photo, et
 * au moins MIN_FACES visages y attendent un nom — comptés comme le jeu
 * « Qui est-ce ? » les proposera dans ce cercle
 * (QuestService::pendingFacesByMedia : visages non associés, hors
 * arrière-plan, hors « inconnu / pas du groupe » de ce cercle et hors
 * réponses du journal).
 */
class AlbumFacesFinder
{
    /** En dessous, pas d'encart (un visage seul ne vaut pas une relance). */
    public const MIN_FACES = 3;

    /** Le même album ne revient pas avant ce nombre d'envois. */
    public const COOLDOWN = 6;

    /** Albums comptés au plus par lettre (le décompte exact coûte quelques requêtes). */
    private const SCAN = 8;

    public function __construct(private QuestService $quests) {}

    /**
     * Un album à proposer, tiré au sort (graine de la semaine) parmi les
     * éligibles.
     *
     * @param  User  $viewer  le destinataire placé dans le cercle visé (User::inCircle)
     * @param  callable  $world  restreint la requête d'albums au monde de la lettre
     * @param  list<string>  $exclude  albums proposés récemment
     * @return array{album: Album, count: int, media_ids: list<string>}|null
     */
    public function find(User $viewer, callable $world, array $exclude, int $seed): ?array
    {
        if (! $viewer->person_id) {
            return null;
        }

        $albums = Album::accessibleBy($viewer)
            ->tap($world)
            ->whereNotIn('albums.id', $exclude)
            // Sa fiche est identifiée sur au moins une photo de l'album.
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('album_media as am')
                ->join('media_person as mp', 'mp.media_id', '=', 'am.media_id')
                ->join('media as m', 'm.id', '=', 'am.media_id')
                ->whereColumn('am.album_id', 'albums.id')
                ->where('mp.person_id', $viewer->person_id)
                ->whereNull('m.deleted_at'))
            // Pré-filtre bon marché : au moins un visage sans nom.
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('album_media as am')
                ->join('detected_faces as df', 'df.media_id', '=', 'am.media_id')
                ->whereColumn('am.album_id', 'albums.id')
                ->where('df.status', 'unmatched')
                ->whereNull('df.person_id'))
            ->orderByRaw('md5(albums.id::text || ?)', [(string) $seed])
            ->limit(self::SCAN)
            ->get(['albums.id', 'albums.name']);

        foreach ($albums as $album) {
            $byMedia = $this->quests->pendingFacesByMedia($viewer, $album);
            $count = array_sum($byMedia);
            if ($count >= self::MIN_FACES) {
                arsort($byMedia);

                return ['album' => $album, 'count' => $count, 'media_ids' => array_slice(array_keys($byMedia), 0, 3)];
            }
        }

        return null;
    }
}
