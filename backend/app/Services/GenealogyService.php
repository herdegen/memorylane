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
