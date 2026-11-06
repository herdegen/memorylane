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
     * Proximité ressentie depuis « moi » (plus petit = plus proche), pour
     * ranger la liste des personnes. Contrairement au nombre de liens,
     * remonter vers les ancêtres coûte vite cher au-delà des grands-parents,
     * alors que les branches latérales restent proches : ma tante, mes
     * neveux, mes cousins passent devant mes arrière-grands-parents, et les
     * petits-enfants des cousins de mes grands-parents les égalent à peine.
     *
     * Parenté par le sang via l'ancêtre commun le plus proche (u générations
     * en remontant, d en redescendant) ; conjoints et belle-famille ensuite ;
     * le reste du graphe après toute la famille.
     *
     * @return array<string, float> id → score
     */
    public function closeness(?string $selfPersonId): array
    {
        if (! $selfPersonId) {
            return [];
        }

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
        foreach (DB::table('person_relationships')->get(['person1_id', 'person2_id']) as $rel) {
            $spouses[$rel->person1_id][] = $rel->person2_id;
            $spouses[$rel->person2_id][] = $rel->person1_id;
        }

        $score = [];
        $keep = function (string $id, float $value) use (&$score) {
            if (! isset($score[$id]) || $value < $score[$id]) {
                $score[$id] = $value;
            }
        };

        // Parenté par le sang depuis une personne, décalée de $offset.
        $blood = function (string $from, float $offset) use ($parents, $children, $keep) {
            $ancestors = [$from => 0];
            $queue = [$from];
            while ($queue) {
                $id = array_shift($queue);
                foreach ($parents[$id] ?? [] as $parent) {
                    if (! isset($ancestors[$parent]) && $ancestors[$id] < 12) {
                        $ancestors[$parent] = $ancestors[$id] + 1;
                        $queue[] = $parent;
                    }
                }
            }
            foreach ($ancestors as $ancestor => $up) {
                $keep($ancestor, $offset + self::kinshipCost($up, 0));
                $depth = [$ancestor => 0];
                $queue = [$ancestor];
                while ($queue) {
                    $id = array_shift($queue);
                    foreach ($children[$id] ?? [] as $child) {
                        if (! isset($depth[$child])) {
                            $depth[$child] = $depth[$id] + 1;
                            // Un ancêtre direct reste noté comme ancêtre (pas
                            // comme « latéral » vu depuis un aïeul plus haut).
                            if (! isset($ancestors[$child])) {
                                $keep($child, $offset + self::kinshipCost($up, $depth[$child]));
                            }
                            $queue[] = $child;
                        }
                    }
                }
            }
        };

        $blood($selfPersonId, 0.0);
        // Mon conjoint et sa famille (ma belle-famille).
        foreach ($spouses[$selfPersonId] ?? [] as $spouse) {
            $blood($spouse, 0.5);
        }
        // Conjoints de mes proches (gendre, belle-sœur, oncle par alliance…).
        foreach ($score as $id => $value) {
            foreach ($spouses[$id] ?? [] as $spouse) {
                $keep($spouse, $value + 0.8);
            }
        }
        $score[$selfPersonId] = 0.0;

        // Le reste du graphe (familles alliées plus lointaines) : après toute
        // la famille, dans l'ordre du nombre de liens.
        foreach ($this->proximity($selfPersonId)['distance'] as $id => $links) {
            if (! isset($score[$id])) {
                $score[$id] = 20.0 + $links;
            }
        }

        return $score;
    }

    /**
     * Coût d'une parenté par le sang : u générations jusqu'à l'ancêtre commun,
     * d pour redescendre. Ancêtres directs (d = 0) : parents et grands-parents
     * proches, puis forte pente au-delà.
     */
    public static function kinshipCost(int $up, int $down): float
    {
        if ($down === 0) {
            return match (true) {
                $up === 0 => 0.0,
                $up === 1 => 1.0,
                $up === 2 => 2.2,
                default => 6.5 + 2.0 * ($up - 3),
            };
        }

        return $up * 1.0 + $down * 0.6;
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
        [$parents, $children] = $this->parentLinks();
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
     * « Ses proches », par le sang seulement : ses ancêtres, et tous les
     * descendants de ses grands-parents — parents, oncles et tantes, cousins,
     * frères et sœurs, neveux, ses propres descendants. Ni conjoints ni
     * belle-famille ; les petits-cousins non plus : pour une branche plus
     * large, on part de la fiche d'un ancêtre plus lointain.
     *
     * @return array<int, string> ids, la personne comprise
     */
    public function closeOf(string $personId): array
    {
        [$parents, $children] = $this->parentLinks();
        $walk = fn (array $start, array $edges) => self::walk($start, $edges);

        $grandparents = [];
        foreach ($parents[$personId] ?? [] as $parentId) {
            array_push($grandparents, ...($parents[$parentId] ?? []));
        }
        // Les descendants de ses grands-parents (et d'elle-même, de ses
        // parents si l'arbre s'arrête là), sur toutes les générations.
        $close = $walk([$personId], $parents)
            + $walk([$personId, ...($parents[$personId] ?? []), ...$grandparents], $children);

        return array_keys($close);
    }

    /**
     * Une branche de l'arbre autour d'une personne, pour la partager avec une
     * autre famille : elle seule (« person »), ses descendants, ses ancêtres,
     * ou les deux (« both »).
     *
     * @return array<int, string> ids, la personne comprise
     */
    public function branchOf(string $personId, string $scope): array
    {
        [$parents, $children] = $this->parentLinks();
        $ids = [$personId => true];
        if (in_array($scope, ['descendants', 'both'], true)) {
            $ids += self::walk([$personId], $children);
        }
        if (in_array($scope, ['ancestors', 'both'], true)) {
            $ids += self::walk([$personId], $parents);
        }

        return array_keys($ids);
    }

    /**
     * Parcours en largeur depuis $start le long des liens $edges (parents ou
     * enfants) : toutes les fiches atteintes, départ compris.
     *
     * @param  list<string>  $start
     * @param  array<string, list<string>>  $edges
     * @return array<string, true>
     */
    private static function walk(array $start, array $edges): array
    {
        $seen = array_fill_keys($start, true);
        for ($queue = $start; $queue !== [];) {
            foreach ($edges[array_shift($queue)] ?? [] as $next) {
                if (! isset($seen[$next])) {
                    $seen[$next] = true;
                    $queue[] = $next;
                }
            }
        }

        return $seen;
    }

    /**
     * Liens de filiation : [parents de chaque fiche, enfants de chaque fiche].
     *
     * @return array{0: array<string, list<string>>, 1: array<string, list<string>>}
     */
    private function parentLinks(): array
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

        return [$parents, $children];
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
     * En anglais : mêmes motifs, libellés construits par englishRelationLabel ;
     * en espagnol, par spanishRelationLabel.
     */
    public static function relationLabel(array $edges, ?string $gender): ?string
    {
        if (app()->getLocale() === 'en') {
            return self::englishRelationLabel($edges, $gender);
        }
        if (app()->getLocale() === 'es') {
            return self::spanishRelationLabel($edges, $gender);
        }

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
     * Libellé anglais du lien : mêmes motifs que relationLabel (et null pour
     * les mêmes chemins), construit à partir de la structure — sens (on monte
     * ou on descend), nombre de générations, genre de la cible ; genre
     * inconnu → forme neutre (parent, child, sibling…). Par alliance :
     * « step- » pour le conjoint d'un ascendant et les descendants du
     * conjoint, « -in-law » pour les ascendants du conjoint et le conjoint
     * d'un descendant, sinon « … by marriage ».
     */
    private static function englishRelationLabel(array $edges, ?string $gender): ?string
    {
        $g = fn (string $female, string $male, string $neutral) => match ($gender) {
            'F' => $female,
            'M' => $male,
            default => $neutral,
        };
        // Ascendant ou descendant à $n générations : mother, grandmother, great-grandmother…
        $line = fn (string $dir, int $n) => str_repeat('great-', max(0, $n - 2)).($n > 1 ? 'grand' : '')
            .($dir === 'parent' ? $g('mother', 'father', 'parent') : $g('daughter', 'son', 'child'));

        $direct = [
            'parent' => $line('parent', 1),
            'parent.parent' => $line('parent', 2),
            'parent.parent.parent' => $line('parent', 3),
            'child' => $line('child', 1),
            'child.child' => $line('child', 2),
            'child.child.child' => $line('child', 3),
            'parent.child' => $g('sister', 'brother', 'sibling'),
            'parent.child.child' => $g('niece', 'nephew', 'niece or nephew'),
            'parent.parent.child' => $g('aunt', 'uncle', 'aunt or uncle'),
            'parent.parent.child.child' => 'first cousin',
            'spouse' => 'partner',
            'spouse.parent' => $line('parent', 1).'-in-law',
            'parent.child.spouse' => $g('sister', 'brother', 'sibling').'-in-law',
            'spouse.parent.child' => $g('sister', 'brother', 'sibling').'-in-law',
            'child.spouse' => $line('child', 1).'-in-law',
        ];

        $key = implode('.', $edges);
        if (isset($direct[$key])) {
            return 'your '.$direct[$key];
        }

        // Même règle qu'en français : un unique pas « conjoint » en tête ou en queue.
        if (count($edges) > 1 && ! in_array('spouse', array_slice($edges, 1, -1), true)) {
            foreach ([['start', array_slice($edges, 1)], ['end', array_slice($edges, 0, -1)]] as [$where, $rest]) {
                $edge = $where === 'start' ? $edges[0] : end($edges);
                if ($edge !== 'spouse' || ! isset($direct[implode('.', $rest)])) {
                    continue;
                }
                // Lignée droite (que des montées ou que des descentes).
                if (count(array_unique($rest)) === 1 && $rest[0] !== 'spouse') {
                    $n = count($rest);
                    $inLaw = ($where === 'start') === ($rest[0] === 'parent');

                    return 'your '.($inLaw
                        ? $line($rest[0], $n).'-in-law'
                        : 'step'.($n > 1 ? '-' : '').$line($rest[0], $n));
                }

                return 'your '.$direct[implode('.', $rest)].' by marriage';
            }
        }

        return null;
    }

    /**
     * Libellé espagnol du lien (tutoiement « tu ») : mêmes motifs que
     * relationLabel (et null pour les mêmes chemins), genré sur la cible ;
     * genre inconnu → masculin générique, comme en français. Par alliance :
     * suegro / yerno pour les parents du conjoint et le conjoint d'un enfant,
     * padrastro / hijastro pour le conjoint d'un parent et les enfants du
     * conjoint ; au-delà d'une génération et pour les autres motifs,
     * « … político / política » (tío político, abuela política, cuñada política…).
     */
    private static function spanishRelationLabel(array $edges, ?string $gender): ?string
    {
        $f = $gender === 'F';
        // Ascendant ou descendant à $n générations : madre, abuela, bisabuela…
        $line = fn (string $dir, int $n) => match (true) {
            $dir === 'parent' && $n === 1 => $f ? 'madre' : 'padre',
            $dir === 'parent' && $n === 2 => $f ? 'abuela' : 'abuelo',
            $dir === 'parent' => $f ? 'bisabuela' : 'bisabuelo',
            $n === 1 => $f ? 'hija' : 'hijo',
            $n === 2 => $f ? 'nieta' : 'nieto',
            default => $f ? 'bisnieta' : 'bisnieto',
        };
        $politico = $f ? ' política' : ' político';

        $direct = [
            'parent' => $line('parent', 1),
            'parent.parent' => $line('parent', 2),
            'parent.parent.parent' => $line('parent', 3),
            'child' => $line('child', 1),
            'child.child' => $line('child', 2),
            'child.child.child' => $line('child', 3),
            'parent.child' => $f ? 'hermana' : 'hermano',
            'parent.child.child' => $f ? 'sobrina' : 'sobrino',
            'parent.parent.child' => $f ? 'tía' : 'tío',
            'parent.parent.child.child' => $f ? 'prima hermana' : 'primo hermano',
            'spouse' => $f ? 'esposa' : 'esposo',
            'spouse.parent' => $f ? 'suegra' : 'suegro',
            'parent.child.spouse' => $f ? 'cuñada' : 'cuñado',
            'spouse.parent.child' => $f ? 'cuñada' : 'cuñado',
            'child.spouse' => $f ? 'nuera' : 'yerno',
        ];

        $key = implode('.', $edges);
        if (isset($direct[$key])) {
            return 'tu '.$direct[$key];
        }

        // Même règle qu'en français : un unique pas « conjoint » en tête ou en queue.
        if (count($edges) > 1 && ! in_array('spouse', array_slice($edges, 1, -1), true)) {
            foreach ([['start', array_slice($edges, 1)], ['end', array_slice($edges, 0, -1)]] as [$where, $rest]) {
                $edge = $where === 'start' ? $edges[0] : end($edges);
                $restKey = implode('.', $rest);
                if ($edge !== 'spouse' || ! isset($direct[$restKey])) {
                    continue;
                }
                // Lignée droite (que des montées ou que des descentes) sur une génération.
                if (count($rest) === 1 && $rest[0] !== 'spouse') {
                    $inLaw = ($where === 'start') === ($rest[0] === 'parent');

                    return 'tu '.match (true) {
                        $inLaw && $rest[0] === 'parent' => $f ? 'suegra' : 'suegro',
                        $inLaw => $f ? 'nuera' : 'yerno',
                        $rest[0] === 'parent' => $f ? 'madrastra' : 'padrastro',
                        default => $f ? 'hijastra' : 'hijastro',
                    };
                }

                // « primo hermano político » sonne mal : « primo político ».
                $base = $restKey === 'parent.parent.child.child' ? ($f ? 'prima' : 'primo') : $direct[$restKey];

                return 'tu '.$base.$politico;
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
