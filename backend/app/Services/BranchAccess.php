<?php

namespace App\Services;

use App\Models\BranchShare;
use App\Models\Person;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Partage par branche : qui en fait partie, calculé à la volée sur l'arbre.
 *
 * Un compte voit un partage de branche dont la racine est l'une de SES
 * ascendantes (ou lui-même), ou — si le partage inclut les conjoints —
 * l'une des ascendantes de son conjoint actuel (ou ce conjoint). Les unions
 * terminées ne comptent pas. Mémorisé pour la requête en cours.
 */
class BranchAccess
{
    /** @var array<string, array{direct: list<string>, spouse: list<string>}> */
    private array $memo = [];

    /** @var array<string, bool> Existe-t-il au moins un partage de ce type ? */
    private array $anyShares = [];

    /**
     * Racines de branche qui englobent ce compte.
     *
     * @return array{direct: list<string>, spouse: list<string>}
     */
    public function rootsFor(User $user): array
    {
        if (! $user->person_id) {
            return ['direct' => [], 'spouse' => []];
        }

        return $this->memo[$user->person_id] ??= $this->computeRoots($user->person_id);
    }

    /** Sous-requête des ids partagés (média ou album) visibles de ce compte, ou null s'il n'y en a aucun. */
    public function sharedIds(User $user, string $type)
    {
        // Aucun partage de ce type : inutile de parcourir l'arbre.
        // (seul le « oui » est mémorisé : un partage peut naître pendant la requête).
        if (! ($this->anyShares[$type] ?? false)) {
            if (! DB::table('branch_shares')->where('shareable_type', $type)->exists()) {
                return null;
            }
            $this->anyShares[$type] = true;
        }
        $roots = $this->rootsFor($user);
        if ($roots['direct'] === [] && $roots['spouse'] === []) {
            return null;
        }

        return DB::table('branch_shares')
            ->where('shareable_type', $type)
            ->where(function ($q) use ($roots) {
                if ($roots['direct'] !== []) {
                    $q->orWhereIn('person_id', $roots['direct']);
                }
                if ($roots['spouse'] !== []) {
                    $q->orWhere(fn ($s) => $s->where('include_spouses', true)->whereIn('person_id', $roots['spouse']));
                }
            })
            ->select('shareable_id');
    }

    /**
     * Qui fait partie de la branche (aperçu avant de partager).
     *
     * @return array{people: int, accounts: int, names: list<string>}
     */
    public function members(Person $root, bool $withSpouses): array
    {
        [, $children] = $this->parentLinks();
        $ids = [$root->id => true];
        for ($queue = [$root->id]; $queue !== [];) {
            foreach ($children[array_shift($queue)] ?? [] as $child) {
                if (! isset($ids[$child])) {
                    $ids[$child] = true;
                    $queue[] = $child;
                }
            }
        }
        if ($withSpouses) {
            $spouses = $this->spouses();
            foreach (array_keys($ids) as $id) {
                foreach ($spouses[$id] ?? [] as $s) {
                    $ids[$s] = true;
                }
            }
        }
        $ids = array_keys($ids);
        $accounts = User::whereIn('person_id', $ids)->get(['name']);

        return [
            'people' => Person::whereIn('id', $ids)->count(),
            'accounts' => $accounts->count(),
            'names' => $accounts->pluck('name')->sort()->values()->take(12)->all(),
        ];
    }

    /** @return array{direct: list<string>, spouse: list<string>} */
    private function computeRoots(string $personId): array
    {
        [$parents] = $this->parentLinks();
        $ancestors = function (string $id) use ($parents): array {
            $seen = [$id => true];
            for ($queue = [$id]; $queue !== [];) {
                foreach ($parents[array_shift($queue)] ?? [] as $p) {
                    if (! isset($seen[$p])) {
                        $seen[$p] = true;
                        $queue[] = $p;
                    }
                }
            }

            return array_keys($seen);
        };

        $viaSpouse = [];
        foreach ($this->spouses()[$personId] ?? [] as $spouse) {
            $viaSpouse = array_merge($viaSpouse, $ancestors($spouse));
        }

        return ['direct' => $ancestors($personId), 'spouse' => array_values(array_unique($viaSpouse))];
    }

    /** @var array{0: array<string, list<string>>, 1: array<string, list<string>>}|null */
    private ?array $links = null;

    private function parentLinks(): array
    {
        if ($this->links === null) {
            $parents = $children = [];
            foreach (DB::table('people')->whereNull('deleted_at')->get(['id', 'father_id', 'mother_id']) as $p) {
                foreach ([$p->father_id, $p->mother_id] as $parentId) {
                    if ($parentId) {
                        $parents[$p->id][] = $parentId;
                        $children[$parentId][] = $p->id;
                    }
                }
            }
            $this->links = [$parents, $children];
        }

        return $this->links;
    }

    /** @var array<string, list<string>>|null Conjoints actuels (unions non terminées). */
    private ?array $spouseMap = null;

    private function spouses(): array
    {
        if ($this->spouseMap === null) {
            $this->spouseMap = [];
            $unions = DB::table('person_relationships')
                ->where(fn ($q) => $q->whereNull('end_date')->orWhere('end_date', '>', now()->toDateString()))
                ->get(['person1_id', 'person2_id']);
            foreach ($unions as $rel) {
                $this->spouseMap[$rel->person1_id][] = $rel->person2_id;
                $this->spouseMap[$rel->person2_id][] = $rel->person1_id;
            }
        }

        return $this->spouseMap;
    }

    /** Partages de branche d'un média ou d'un album, pour l'affichage. */
    public static function listFor(string $type, string $id, ?User $viewer = null): array
    {
        return BranchShare::with('person:id,name,first_name,last_name')
            ->where('shareable_type', $type)->where('shareable_id', $id)
            ->orderBy('created_at')->get()
            ->map(fn (BranchShare $s) => [
                'id' => $s->id,
                'person' => ['id' => $s->person_id, 'name' => $s->person?->name],
                'include_spouses' => $s->include_spouses,
            ])->all();
    }
}
