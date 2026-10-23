<?php

namespace App\Services;

use App\Models\Album;
use App\Models\Household;
use App\Models\Media;
use App\Models\Person;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Albums « vivants » : albums du cercle courant, couvertures qui défilent,
 * album à l'honneur et rangées thématiques. Partagé par la page Albums et
 * l'interface télévision.
 */
class AlbumDiscovery
{
    public function __construct(protected MediaService $mediaService) {}

    /** Photos tirées au sort par album pour les couvertures vivantes. */
    public const PREVIEWS_PER_ALBUM = 6;

    /**
     * Couvertures vivantes : 6 photos par album, tirées au sort à chaque visite
     * (« à la une » et photos avec des proches identifiés d'abord), plus la
     * période et la date du dernier ajout (rangées de la page).
     */
    public function attach($albums): void
    {
        $ids = $albums->pluck('id')->all();
        if ($ids === []) {
            return;
        }

        $picked = collect(DB::select(
            'select album_id, media_id from (
                select am.album_id, am.media_id, row_number() over (
                    partition by am.album_id
                    order by (am.pin_order is not null) desc,
                             exists (select 1 from detected_faces df where df.media_id = m.id and df.person_id is not null) desc,
                             random()
                ) as rn
                from album_media am join media m on m.id = am.media_id
                where am.album_id in ('.implode(',', array_fill(0, count($ids), '?')).')
                  and m.deleted_at is null and m.type = \'photo\'
            ) t where rn <= ?',
            [...$ids, self::PREVIEWS_PER_ALBUM]
        ));
        $media = Media::with('conversions')->whereIn('id', $picked->pluck('media_id')->unique())->get()->keyBy('id');

        $stats = DB::table('album_media as am')->join('media as m', 'm.id', '=', 'am.media_id')
            ->whereIn('am.album_id', $ids)->whereNull('m.deleted_at')
            ->groupBy('am.album_id')
            ->selectRaw('am.album_id, min(m.taken_at) as first_at, max(m.taken_at) as last_at, max(am.created_at) as added_at,
                sum(case when extract(month from m.taken_at) = ? and extract(year from m.taken_at) < ? then 1 else 0 end) as month_photos,
                min(case when extract(month from m.taken_at) = ? and extract(year from m.taken_at) < ? then extract(year from m.taken_at) end) as month_year',
                [now()->month, now()->year, now()->month, now()->year])
            ->get()->keyBy('album_id');

        $albums->each(function (Album $album) use ($picked, $media, $stats) {
            $urls = $picked->where('album_id', $album->id)->pluck('media_id')->shuffle()
                ->map(fn ($id) => isset($media[$id]) ? [
                    'small' => $this->mediaService->thumbnailUrl($media[$id]),
                    'medium' => $this->mediaService->thumbnailUrl($media[$id], ['medium', 'small', 'thumbnail']),
                    // Plein écran (fond de la page Albums sur la télévision).
                    'large' => $this->mediaService->thumbnailUrl($media[$id], ['large', 'medium', 'small']),
                ] : null)->filter()->values();
            $album->previews = $urls;
            $st = $stats[$album->id] ?? null;
            $album->first_at = $st?->first_at ? substr($st->first_at, 0, 10) : null;
            $album->last_at = $st?->last_at ? substr($st->last_at, 0, 10) : null;
            $album->added_at = $st?->added_at;
            $album->month_photos = (int) ($st->month_photos ?? 0);
            $album->month_year = $st?->month_year ? (int) $st->month_year : null;
        });
    }

    /**
     * Ce qui rend la page différente à chaque visite : l'album à l'honneur
     * (anniversaire ce mois-ci, sinon récemment enrichi, sinon au hasard) et
     * des rangées thématiques — sur les albums du cercle courant.
     */
    public function sections(User $user, $albums): array
    {
        $here = $albums->filter(fn ($a) => $a->in_context && ($a->media_count ?? 0) > 0 && count($a->previews ?? []) > 0)->values();
        if ($here->isEmpty()) {
            return ['featured' => null, 'rows' => [], 'decades' => []];
        }

        $thisMonth = $here->filter(fn ($a) => $a->month_photos > 0)->sortByDesc('month_photos')->values();
        $recent = $here->filter(fn ($a) => $a->added_at && now()->diffInDays($a->added_at, true) <= 45)->sortByDesc('added_at')->values();

        if ($thisMonth->isNotEmpty()) {
            $f = $thisMonth->take(3)->random();
            $years = now()->year - $f->month_year;
            $featured = ['id' => $f->id, 'reason' => $years === 1 ? __('Il y a un an ce mois-ci') : __('Il y a :years ans ce mois-ci', ['years' => $years])];
        } elseif ($recent->isNotEmpty()) {
            $featured = ['id' => $recent->first()->id, 'reason' => __('Récemment enrichi')];
        } else {
            $featured = ['id' => $here->random()->id, 'reason' => __('À redécouvrir')];
        }

        $rows = [];
        if ($thisMonth->count() >= 1) {
            $rows[] = ['key' => 'month', 'title' => __('Ce mois-ci, les années passées'), 'ids' => $thisMonth->pluck('id')->all()];
        }
        if ($recent->count() >= 2) {
            $rows[] = ['key' => 'recent', 'title' => __('Récemment enrichis'), 'ids' => $recent->take(12)->pluck('id')->all()];
        }

        // Les proches les plus présents dans ces albums : une rangée chacun.
        $ids = $here->pluck('id')->all();
        $top = DB::table('media_person as mp')->join('album_media as am', 'am.media_id', '=', 'mp.media_id')
            ->whereIn('am.album_id', $ids)
            ->whereIn('mp.person_id', Person::inContext($user)->select('people.id'))
            ->when($user->person_id, fn ($q) => $q->where('mp.person_id', '!=', $user->person_id))
            ->groupBy('mp.person_id')->selectRaw('mp.person_id, count(distinct am.album_id) as albums')
            ->having(DB::raw('count(distinct am.album_id)'), '>=', 2)
            ->orderByDesc('albums')->limit(2)->pluck('person_id');
        foreach (Person::whereIn('id', $top)->get(['id', 'name', 'first_name']) as $person) {
            $withIds = DB::table('media_person as mp')->join('album_media as am', 'am.media_id', '=', 'mp.media_id')
                ->where('mp.person_id', $person->id)->whereIn('am.album_id', $ids)
                ->groupBy('am.album_id')->orderByRaw('count(*) desc')->pluck('am.album_id')->all();
            $rows[] = ['key' => 'person-'.$person->id, 'title' => __('Avec :name', ['name' => $person->first_name ?: explode(' ', $person->name)[0]]), 'ids' => $withIds];
        }

        // Décennies (d'après la première photo datée de chaque album).
        $decades = $here->filter(fn ($a) => $a->first_at)
            ->groupBy(fn ($a) => intdiv((int) substr($a->first_at, 0, 4), 10) * 10)
            ->map(fn ($g, $decade) => ['decade' => (int) $decade, 'ids' => $g->pluck('id')->all()])
            ->sortKeys()->values()->all();

        // Ordre des rangées mélangé (sauf « ce mois-ci » en tête) : la page
        // bouge. Une rangée doit apporter au moins 2 albums pas encore montrés
        // (sinon, avec peu d'albums, toutes les rangées se ressemblent) ; 4 au plus.
        $first = array_values(array_filter($rows, fn ($r) => $r['key'] === 'month'));
        $others = collect($rows)->reject(fn ($r) => $r['key'] === 'month')->shuffle()->values()->all();
        $shown = [$featured['id'] => true];
        $kept = [];
        foreach ([...$first, ...$others] as $row) {
            $new = array_filter($row['ids'], fn ($id) => ! isset($shown[$id]));
            if (count($row['ids']) < 2 || count($new) < 2 || count($kept) >= 4) {
                continue;
            }
            foreach ($row['ids'] as $id) {
                $shown[$id] = true;
            }
            $kept[] = $row;
        }

        return ['featured' => $featured, 'rows' => $kept, 'decades' => $decades];
    }

    /**
     * Albums qui appartiennent au cercle courant.
     * - Cercle d'amis : partagés avec lui, ou montrant un ami du cercle.
     * - Famille : partagés avec un cercle familial, ou sans cercle du tout
     *   (privés) ; un album partagé seulement avec des cercles d'amis est
     *   « ailleurs ».
     *
     * @return array<string,true>
     */
    public function inContextIds(User $user, $albums): array
    {
        $circle = $user->currentCircle();
        $ids = [];

        if ($circle?->isFriends()) {
            $friendIds = $circle->people()
                ->when($user->person_id, fn ($q) => $q->where('people.id', '!=', $user->person_id))
                ->pluck('people.id');
            $withFriends = DB::table('album_media as am')
                ->join('media_person as mp', 'mp.media_id', '=', 'am.media_id')
                ->whereIn('am.album_id', $albums->pluck('id'))
                ->whereIn('mp.person_id', $friendIds)
                ->distinct()
                ->pluck('am.album_id')
                ->flip();
            foreach ($albums as $album) {
                if ($album->households->contains('id', $circle->id) || isset($withFriends[$album->id])) {
                    $ids[$album->id] = true;
                }
            }

            return $ids;
        }

        $familyCircles = Household::where('kind', Household::KIND_FAMILY)->pluck('id')->flip();
        foreach ($albums as $album) {
            $circles = $album->households->pluck('id');
            if ($circles->isEmpty() || $circles->contains(fn ($id) => isset($familyCircles[$id]))) {
                $ids[$album->id] = true;
            }
        }

        return $ids;
    }
}
