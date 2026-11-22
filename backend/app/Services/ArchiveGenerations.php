<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Génération de chaque personne d'un fonds d'archives, d'après l'arbre :
 * l'enfant est une génération sous ses parents, le conjoint dans celle de
 * son conjoint. Les branches se rejoignent par leurs ancêtres communs
 * (ascendants chargés sur ARCHIVE_DEPTH niveaux) ; des branches sans ancêtre
 * commun connu sont alignées par les années de naissance.
 *
 * La génération 0 est la plus ancienne parmi les personnes du fonds.
 */
class ArchiveGenerations
{
    /** Écart moyen entre deux générations, pour aligner des branches sans lien connu. */
    public const YEARS_PER_GENERATION = 28;

    private const ARCHIVE_DEPTH = 12;

    /**
     * @param  array<int,string>  $personIds  personnes du fonds
     * @return array{levels: array<string,int>, viewer: ?int}
     *                                                         viewer : génération du visiteur s'il est relié aux personnes du fonds par l'arbre
     */
    public function levels(array $personIds, ?string $viewerId): array
    {
        $personIds = array_values(array_unique($personIds));
        if ($personIds === []) {
            return ['levels' => [], 'viewer' => null];
        }

        // Personnes du fonds, visiteur et leurs ascendants.
        $nodes = [];
        $frontier = array_values(array_unique(array_filter([...$personIds, $viewerId])));
        for ($depth = 0; $depth <= self::ARCHIVE_DEPTH && $frontier !== []; $depth++) {
            $next = [];
            foreach (DB::table('people')->whereIn('id', $frontier)->whereNull('deleted_at')->get(['id', 'father_id', 'mother_id', 'birth_date']) as $row) {
                if (isset($nodes[$row->id])) {
                    continue;
                }
                $nodes[$row->id] = $row;
                foreach ([$row->father_id, $row->mother_id] as $parent) {
                    if ($parent && ! isset($nodes[$parent])) {
                        $next[] = $parent;
                    }
                }
            }
            $frontier = array_values(array_unique($next));
        }

        // Liens : parent → enfant (+1), conjoints (0).
        $edges = [];
        foreach ($nodes as $id => $row) {
            foreach ([$row->father_id, $row->mother_id] as $parent) {
                if ($parent && isset($nodes[$parent])) {
                    $edges[$parent][] = [$id, 1];
                    $edges[$id][] = [$parent, -1];
                }
            }
        }
        $ids = array_keys($nodes);
        foreach (DB::table('person_relationships')->whereIn('person1_id', $ids)->whereIn('person2_id', $ids)->get(['person1_id', 'person2_id']) as $r) {
            $edges[$r->person1_id][] = [$r->person2_id, 0];
            $edges[$r->person2_id][] = [$r->person1_id, 0];
        }

        // Composantes : niveaux relatifs par parcours en largeur.
        $level = [];
        $component = [];
        $components = 0;
        foreach (array_merge($personIds, array_filter([$viewerId])) as $start) {
            if (! isset($nodes[$start]) || isset($level[$start])) {
                continue;
            }
            $level[$start] = 0;
            $component[$start] = $components;
            $queue = [$start];
            while ($queue !== []) {
                $u = array_shift($queue);
                foreach ($edges[$u] ?? [] as [$v, $delta]) {
                    if (! isset($level[$v])) {
                        $level[$v] = $level[$u] + $delta;
                        $component[$v] = $components;
                        $queue[] = $v;
                    }
                }
            }
            $components++;
        }

        // Alignement des composantes par les années de naissance.
        $bases = [];
        foreach ($level as $id => $l) {
            if ($nodes[$id]->birth_date) {
                $bases[$component[$id]][] = (int) substr($nodes[$id]->birth_date, 0, 4) - self::YEARS_PER_GENERATION * $l;
            }
        }
        $base = array_map(fn ($years) => array_sum($years) / count($years), $bases);
        $ref = $base[$component[$personIds[0]] ?? -1] ?? (reset($base) ?: null);
        $global = function (string $id) use ($level, $component, $base, $ref) {
            $offset = ($ref !== null && isset($base[$component[$id]])) ? (int) round(($base[$component[$id]] - $ref) / self::YEARS_PER_GENERATION) : 0;

            return $level[$id] + $offset;
        };

        $levels = [];
        foreach ($personIds as $id) {
            if (isset($level[$id])) {
                $levels[$id] = $global($id);
            }
        }
        if ($levels === []) {
            return ['levels' => [], 'viewer' => null];
        }
        $min = min($levels);
        $levels = array_map(fn ($l) => $l - $min, $levels);

        // Le visiteur n'a une génération que s'il est relié par l'arbre (même composante).
        $viewer = null;
        if ($viewerId && isset($level[$viewerId])) {
            $linked = collect(array_keys($levels))->contains(fn ($id) => $component[$id] === $component[$viewerId]);
            $viewer = $linked ? $global($viewerId) - $min : null;
        }

        return ['levels' => $levels, 'viewer' => $viewer];
    }

    /** « La génération de vos grands-parents », « Votre génération »… (null si inconnu). */
    public static function relationLabel(int $level, ?int $viewer): ?string
    {
        if ($viewer === null) {
            return null;
        }
        $d = $viewer - $level;

        return match (true) {
            $d === 0 => __('Votre génération'),
            $d === 1 => __('La génération de vos parents'),
            $d === 2 => __('La génération de vos grands-parents'),
            $d === 3 => __('La génération de vos arrière-grands-parents'),
            $d === 4 => __('La génération de vos arrière-arrière-grands-parents'),
            $d >= 5 => __(':count générations avant vous', ['count' => $d]),
            $d === -1 => __('La génération de vos enfants'),
            $d === -2 => __('La génération de vos petits-enfants'),
            default => __(':count générations après vous', ['count' => -$d]),
        };
    }
}
