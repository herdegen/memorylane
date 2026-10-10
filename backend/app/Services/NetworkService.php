<?php

namespace App\Services;

use App\Models\Album;
use App\Models\Media;
use App\Models\Person;
use App\Models\User;
use App\Services\Vision\AvatarFacePositionService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Réseau de personnes « tissé par les photos » : deux personnes sont liées
 * quand elles sont taguées sur une même photo (lien fort, poids = nb de
 * photos), ou dans un même album (lien faible, masqué par défaut côté front).
 *
 * Calculé PAR VIEWER sur Media::scopeAccessibleBy : un lien n'est jamais
 * révélé par une photo que le viewer ne peut pas voir.
 */
class NetworkService
{
    /** Lot mis en cache : le graphe change peu et le calcul agrège tout. */
    private const CACHE_TTL = 300;

    public function __construct(
        private AvatarFacePositionService $avatarPositions,
    ) {}

    /**
     * @return array{nodes: array<int, array>, edges: array<int, array>, self_id: ?string}
     */
    public function graphFor(User $user): array
    {
        return Cache::remember($this->cacheKey($user), self::CACHE_TTL, fn () => $this->build($user));
    }

    public function forget(User $user): void
    {
        Cache::forget($this->cacheKey($user));
    }

    /** Le graphe dépend du cercle courant (fiches du contexte). */
    private function cacheKey(User $user): string
    {
        return "network:graph:{$user->id}:".($user->currentCircle()?->id ?? 'none');
    }

    /**
     * Médias que les deux personnes partagent ET que le viewer peut voir.
     */
    public function mediaTogether(User $user, string $personA, string $personB, int $limit = 24)
    {
        return Media::accessibleBy($user)
            ->whereHas('people', fn ($q) => $q->where('people.id', $personA))
            ->whereHas('people', fn ($q) => $q->where('people.id', $personB))
            ->with('conversions')
            ->orderByRaw('COALESCE(taken_at, created_at) DESC')
            ->limit($limit)
            ->get();
    }

    private function build(User $user): array
    {
        $accessible = Media::accessibleBy($user)->select('media.id');
        $yearExpr = $this->yearExpression('m');

        // Liens forts : co-présence sur une même photo.
        $strong = DB::table('media_person as a')
            ->join('media_person as b', function ($j) {
                $j->on('a.media_id', '=', 'b.media_id')->whereColumn('a.person_id', '<', 'b.person_id');
            })
            ->join('media as m', 'm.id', '=', 'a.media_id')
            ->whereNull('m.deleted_at')
            ->whereIn('a.media_id', $accessible)
            ->groupBy('a.person_id', 'b.person_id')
            ->selectRaw("a.person_id as source, b.person_id as target, COUNT(*) as weight, MIN({$yearExpr}) as first_year, MAX({$yearExpr}) as last_year")
            ->get();

        // Liens faibles : présents dans un même album accessible (sur des
        // médias accessibles), sans avoir de photo commune.
        $albumPresence = DB::table('album_media as am')
            ->join('media_person as mp', 'mp.media_id', '=', 'am.media_id')
            ->join('media as m', 'm.id', '=', 'am.media_id')
            ->whereNull('m.deleted_at')
            ->whereIn('am.album_id', Album::accessibleBy($user)->select('albums.id'))
            ->whereIn('am.media_id', Media::accessibleBy($user)->select('media.id'))
            ->select('am.album_id', 'mp.person_id')
            ->selectRaw("MIN({$yearExpr}) as year")
            ->groupBy('am.album_id', 'mp.person_id');

        $weak = DB::query()
            ->fromSub($albumPresence, 'x')
            ->joinSub($albumPresence, 'y', function ($j) {
                $j->on('x.album_id', '=', 'y.album_id')->whereColumn('x.person_id', '<', 'y.person_id');
            })
            ->groupBy('x.person_id', 'y.person_id')
            ->selectRaw('x.person_id as source, y.person_id as target, COUNT(*) as weight, MIN(CASE WHEN x.year > y.year THEN x.year ELSE y.year END) as first_year')
            ->get();

        $strongKeys = [];
        foreach ($strong as $e) {
            $strongKeys[$e->source.'|'.$e->target] = true;
        }

        $edges = [];
        foreach ($strong as $e) {
            $edges[] = $this->edge($e, 'photo');
        }
        foreach ($weak as $e) {
            if (! isset($strongKeys[$e->source.'|'.$e->target])) {
                $edges[] = $this->edge($e, 'album');
            }
        }

        $personIds = collect($edges)->flatMap(fn ($e) => [$e['source'], $e['target']]);
        if ($user->person_id) {
            $personIds->push($user->person_id);
        }

        // Fiches du cercle courant uniquement (famille : répertoire familial ;
        // amis : fiches du cercle) ; les liens vers les autres disparaissent.
        $inContext = Person::inContext($user)
            ->whereIn('people.id', $personIds->unique()->values())
            ->pluck('people.id')
            ->flip();
        $edges = array_values(array_filter($edges, fn ($e) => isset($inContext[$e['source']], $inContext[$e['target']])));
        $personIds = $inContext->keys();

        // Nombre de photos accessibles par personne + première année.
        $stats = DB::table('media_person as mp')
            ->join('media as m', 'm.id', '=', 'mp.media_id')
            ->whereNull('m.deleted_at')
            ->whereIn('mp.media_id', Media::accessibleBy($user)->select('media.id'))
            ->whereIn('mp.person_id', $personIds)
            ->groupBy('mp.person_id')
            ->selectRaw("mp.person_id, COUNT(*) as photos, MIN({$yearExpr}) as first_year")
            ->get()
            ->keyBy('person_id');

        $people = Person::whereIn('id', $personIds)
            ->with('avatar.conversions')
            ->withMatchedFacesCount()
            ->get();
        $positions = $this->avatarPositions->forPeople($people);

        $nodes = $people->map(fn (Person $p) => [
            'id' => $p->id,
            'name' => $p->name,
            'avatar_url' => $this->avatarUrl($p),
            'avatar_position' => $positions[$p->id] ?? null,
            'photos' => (int) ($stats[$p->id]->photos ?? 0),
            'first_year' => isset($stats[$p->id]) ? (int) $stats[$p->id]->first_year : null,
            'is_self' => $p->id === $user->person_id,
        ])->values()->all();

        // Photos réunissant au moins deux personnes (celles qui tissent les liens).
        $photoCount = DB::table('media_person as mp')
            ->join('media as m', 'm.id', '=', 'mp.media_id')
            ->whereNull('m.deleted_at')
            ->whereIn('mp.media_id', Media::accessibleBy($user)->select('media.id'))
            ->groupBy('mp.media_id')
            ->havingRaw('COUNT(*) >= 2')
            ->select('mp.media_id')
            ->get()
            ->count();

        return [
            'nodes' => $nodes,
            'edges' => $edges,
            'self_id' => $user->person_id,
            'photo_count' => $photoCount,
        ];
    }

    private function edge(object $e, string $kind): array
    {
        return [
            'source' => $e->source,
            'target' => $e->target,
            'weight' => (int) $e->weight,
            'first_year' => $e->first_year !== null ? (int) $e->first_year : null,
            'last_year' => isset($e->last_year) ? (int) $e->last_year : null,
            'kind' => $kind,
        ];
    }

    /** Année de prise de vue (repli : date d'import), portable pgsql/sqlite. */
    private function yearExpression(string $alias): string
    {
        $col = "COALESCE({$alias}.taken_at, {$alias}.created_at)";

        return DB::getDriverName() === 'sqlite'
            ? "CAST(strftime('%Y', {$col}) AS INTEGER)"
            : "CAST(EXTRACT(YEAR FROM {$col}) AS INTEGER)";
    }

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
