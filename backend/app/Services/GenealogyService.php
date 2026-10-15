<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Proximité généalogique : distance de parenté (BFS) entre chaque personne et
 * la fiche « moi » de l'utilisateur, sur le graphe non orienté
 * parents/enfants/conjoints. Sert de critère de tri (personnes proches ou
 * récentes d'abord) dans la liste des personnes, la recherche globale et le
 * choix de candidats pour l'identification de visage.
 */
class GenealogyService
{
    /**
     * Distance de parenté à « moi » (BFS) et nombre de proches directs, pour
     * chaque personne du graphe.
     *
     * @return array{distance: array<string,int>, degree: array<string,int>}
     */
    public function proximity(?string $selfPersonId): array
    {
        ['adjacency' => $adj, 'degree' => $degree] = $this->graph();

        // BFS depuis « moi » : distance en nombre de liens de parenté.
        $distance = [];
        if ($selfPersonId && isset($adj[$selfPersonId])) {
            $distance[$selfPersonId] = 0;
            $queue = [$selfPersonId];
            while ($queue) {
                $current = array_shift($queue);
                foreach ($adj[$current] as $neighbour) {
                    if (! isset($distance[$neighbour])) {
                        $distance[$neighbour] = $distance[$current] + 1;
                        $queue[] = $neighbour;
                    }
                }
            }
        }

        return ['distance' => $distance, 'degree' => $degree];
    }

    /**
     * « Sa famille » vue depuis une personne : ses parents par le sang (tous
     * les descendants de tous ses ancêtres — frères et sœurs, cousins,
     * cousins de sa mère, de sa grand-mère…) et leurs conjoints ; plus, pour
     * elle seule, sa belle-famille (la famille de son propre conjoint, de la
     * même façon). La famille des autres conjoints n'en fait pas partie : le
     * père de l'épouse de mon frère est hors famille pour moi. Unions
     * terminées ignorées.
     *
     * @return array<int, string> ids, la personne comprise
     */
    public function familyOf(string $personId): array
    {
        $parents = [];
        $children = [];
        foreach (DB::table('people')->whereNull('deleted_at')->get(['id', 'father_id', 'mother_id']) as $p) {
            foreach ([$p->father_id, $p->mother_id] as $parentId) {
                if ($parentId) {
                    $parents[$p->id][] = $parentId;
                    $children[$parentId][] = $p->id;
                }
            }
        }
        $spouses = [];
        $unions = DB::table('person_relationships')
            ->where(fn ($q) => $q->whereNull('end_date')->orWhere('end_date', '>', now()->toDateString()))
            ->get(['person1_id', 'person2_id']);
        foreach ($unions as $rel) {
            $spouses[$rel->person1_id][] = $rel->person2_id;
            $spouses[$rel->person2_id][] = $rel->person1_id;
        }

        // Ancêtres de $id (et lui-même), puis tous leurs descendants ; plus
        // les conjoints de tous ceux-là.
        $familyOfBlood = function (string $id) use ($parents, $children, $spouses): array {
            $ancestors = [$id => true];
            for ($queue = [$id]; $queue !== [];) {
                foreach ($parents[array_shift($queue)] ?? [] as $parentId) {
                    if (! isset($ancestors[$parentId])) {
                        $ancestors[$parentId] = true;
                        $queue[] = $parentId;
                    }
                }
            }
            $blood = $ancestors;
            for ($queue = array_keys($ancestors); $queue !== [];) {
                foreach ($children[array_shift($queue)] ?? [] as $childId) {
                    if (! isset($blood[$childId])) {
                        $blood[$childId] = true;
                        $queue[] = $childId;
                    }
                }
            }
            $family = $blood;
            foreach (array_keys($blood) as $member) {
                foreach ($spouses[$member] ?? [] as $spouseId) {
                    $family[$spouseId] = true;
                }
            }

            return $family;
        };

        $family = $familyOfBlood($personId);
        // Sa belle-famille, pour soi seulement.
        foreach ($spouses[$personId] ?? [] as $spouseId) {
            $family += $familyOfBlood($spouseId);
        }

        return array_keys($family);
    }

    /**
     * Plus court chemin de parenté entre deux personnes (BFS sur le graphe
     * parents/enfants/conjoints), avec le TYPE de chaque pas vu depuis la
     * personne précédente : 'parent' (on monte), 'child' (on descend),
     * 'spouse' (on passe au conjoint). Null si aucun lien.
     *
     * @return array{ids: array<int,string>, edges: array<int,string>}|null
     */
    public function pathBetween(string $fromId, string $toId): ?array
    {
        $adj = $this->labeledGraph();

        if (! isset($adj[$fromId], $adj[$toId])) {
            return null;
        }

        // BFS avec prédécesseurs (id + type d'arête).
        $prev = [$fromId => null];
        $queue = [$fromId];
        while ($queue) {
            $current = array_shift($queue);
            if ($current === $toId) {
                break;
            }
            foreach ($adj[$current] as [$neighbour, $type]) {
                if (! array_key_exists($neighbour, $prev)) {
                    $prev[$neighbour] = [$current, $type];
                    $queue[] = $neighbour;
                }
            }
        }

        if (! array_key_exists($toId, $prev)) {
            return null;
        }

        $ids = [$toId];
        $edges = [];
        $cursor = $toId;
        while ($prev[$cursor] !== null) {
            [$parent, $type] = $prev[$cursor];
            array_unshift($edges, $type);
            array_unshift($ids, $parent);
            $cursor = $parent;
        }

        return ['ids' => $ids, 'edges' => $edges];
    }

    /**
     * Nom français du lien pour les motifs courants (genré sur la cible),
     * « par alliance » quand un unique pas conjoint encadre un motif connu.
     * Null pour les chemins exotiques (le front affichera « lien en N pas »).
     */
    public static function relationLabel(array $edges, ?string $gender): ?string
    {
        $f = $gender === 'F';
        $direct = [
            'parent' => $f ? 'votre mère' : 'votre père',
            'parent.parent' => $f ? 'votre grand-mère' : 'votre grand-père',
            'parent.parent.parent' => $f ? 'votre arrière-grand-mère' : 'votre arrière-grand-père',
            'child' => $f ? 'votre fille' : 'votre fils',
            'child.child' => $f ? 'votre petite-fille' : 'votre petit-fils',
            'child.child.child' => $f ? 'votre arrière-petite-fille' : 'votre arrière-petit-fils',
            'parent.child' => $f ? 'votre sœur' : 'votre frère',
            'parent.child.child' => $f ? 'votre nièce' : 'votre neveu',
            'parent.parent.child' => $f ? 'votre tante' : 'votre oncle',
            'parent.parent.child.child' => $f ? 'votre cousine germaine' : 'votre cousin germain',
            'spouse' => $f ? 'votre conjointe' : 'votre conjoint',
            'spouse.parent' => $f ? 'votre belle-mère' : 'votre beau-père',
            'parent.child.spouse' => $f ? 'votre belle-sœur' : 'votre beau-frère',
            'spouse.parent.child' => $f ? 'votre belle-sœur' : 'votre beau-frère',
            'child.spouse' => $f ? 'votre belle-fille' : 'votre gendre',
        ];

        $key = implode('.', $edges);
        if (isset($direct[$key])) {
            return $direct[$key];
        }

        // Un unique pas « conjoint » en tête ou en queue : motif connu par alliance.
        if (count($edges) > 1 && ! in_array('spouse', array_slice($edges, 1, -1), true)) {
            foreach ([['start', array_slice($edges, 1)], ['end', array_slice($edges, 0, -1)]] as [$where, $rest]) {
                $edge = $where === 'start' ? $edges[0] : end($edges);
                if ($edge === 'spouse' && isset($direct[implode('.', $rest)])) {
                    return $direct[implode('.', $rest)] . ' par alliance';
                }
            }
        }

        return null;
    }

    /**
     * Chemins typés depuis $fromId vers toutes les personnes à au plus
     * $maxSteps liens (un seul BFS) : [id => ['parent', 'child', …]].
     *
     * @return array<string, array<int, string>>
     */
    public function pathsFrom(string $fromId, int $maxSteps): array
    {
        $adj = $this->labeledGraph();
        if (! isset($adj[$fromId])) {
            return [];
        }

        $edges = [$fromId => []];
        $queue = [$fromId];
        while ($queue) {
            $current = array_shift($queue);
            if (count($edges[$current]) >= $maxSteps) {
                continue;
            }
            foreach ($adj[$current] as [$neighbour, $type]) {
                if (! isset($edges[$neighbour])) {
                    $edges[$neighbour] = [...$edges[$current], $type];
                    $queue[] = $neighbour;
                }
            }
        }

        unset($edges[$fromId]);

        return $edges;
    }

    /**
     * Graphe d'adjacence avec type d'arête orienté depuis chaque nœud :
     * adj[a] = [[b, 'parent'|'child'|'spouse'], …].
     *
     * @return array<string, array<int, array{0:string,1:string}>>
     */
    private function labeledGraph(): array
    {
        $people = DB::table('people')
            ->whereNull('deleted_at')
            ->get(['id', 'father_id', 'mother_id']);

        $known = array_flip($people->pluck('id')->all());
        $adj = array_fill_keys(array_keys($known), []);

        foreach ($people as $p) {
            foreach ([$p->father_id, $p->mother_id] as $parentId) {
                if ($parentId && isset($known[$parentId])) {
                    $adj[$p->id][] = [$parentId, 'parent'];
                    $adj[$parentId][] = [$p->id, 'child'];
                }
            }
        }

        foreach (DB::table('person_relationships')->get(['person1_id', 'person2_id']) as $rel) {
            if (isset($known[$rel->person1_id], $known[$rel->person2_id])) {
                $adj[$rel->person1_id][] = [$rel->person2_id, 'spouse'];
                $adj[$rel->person2_id][] = [$rel->person1_id, 'spouse'];
            }
        }

        return $adj;
    }

    /**
     * Construit le graphe d'adjacence non orienté (parents/enfants + conjoints)
     * et le degré (nombre de proches directs) de chaque personne. Requête
     * minimale : seules les colonnes de parenté sont chargées.
     *
     * @return array{adjacency: array<string,array<int,string>>, degree: array<string,int>}
     */
    private function graph(): array
    {
        $people = DB::table('people')
            ->whereNull('deleted_at')
            ->get(['id', 'father_id', 'mother_id']);

        $ids = $people->pluck('id')->all();
        $known = array_flip($ids);
        $adj = array_fill_keys($ids, []);

        // Arêtes parent/enfant (colonnes father_id / mother_id).
        foreach ($people as $p) {
            foreach ([$p->father_id, $p->mother_id] as $parentId) {
                if ($parentId && isset($known[$parentId])) {
                    $adj[$p->id][] = $parentId;
                    $adj[$parentId][] = $p->id;
                }
            }
        }

        // Arêtes conjoint/partenaire (table pivot person_relationships).
        foreach (DB::table('person_relationships')->get(['person1_id', 'person2_id']) as $rel) {
            if (isset($known[$rel->person1_id], $known[$rel->person2_id])) {
                $adj[$rel->person1_id][] = $rel->person2_id;
                $adj[$rel->person2_id][] = $rel->person1_id;
            }
        }

        $degree = [];
        foreach ($adj as $id => $neighbours) {
            $degree[$id] = count(array_unique($neighbours));
        }

        return ['adjacency' => $adj, 'degree' => $degree];
    }
}
