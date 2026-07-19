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
