<?php

namespace Tests\Unit;

use App\Http\Controllers\LifeEventController;
use App\Services\Demo\DemoBuilder;
use Tests\TestCase;

/**
 * Données des familles de la démo (database/demo/*.php) : chaque référence
 * existe, chaque lieu est géocodé, les dates tiennent debout (parents d'un
 * âge plausible, rien avant la naissance ni après le décès). Une erreur ici
 * ferait échouer demo:build en pleine construction.
 */
class DemoDataTest extends TestCase
{
    public function test_les_familles_de_la_demo_sont_coherentes(): void
    {
        $shared = DemoBuilder::shared();
        $families = DemoBuilder::families();
        $this->assertArrayHasKey('fr', $families);

        $errors = [];
        foreach ($families as $fam => $d) {
            $errors = [...$errors, ...array_map(fn ($e) => "{$fam}: {$e}", $this->check($d, $shared))];
        }

        $this->assertSame([], $errors);
    }

    public function test_les_ancetres_communs_sont_rattaches_aux_deux_familles(): void
    {
        $families = DemoBuilder::families();
        if (count($families) < 2) {
            $this->markTestSkipped('Une seule famille de démo.');
        }
        foreach ($families as $fam => $d) {
            $refs = collect($d['people'])->flatMap(fn ($p) => [$p[7], $p[8]])->filter(fn ($k) => str_starts_with((string) $k, 'shared:'));
            $this->assertNotEmpty($refs, "{$fam} : aucun lien vers les ancêtres communs");
        }
    }

    /** @return list<string> */
    private function check(array $d, array $shared): array
    {
        $err = [];
        $places = $d['places'] + $shared['places'];
        $people = $d['people'];
        $friends = [];
        foreach ($d['friends'] as $c) {
            $friends += $c['people'];
        }
        $born = fn ($k) => isset($people[$k]) ? (int) substr($people[$k][3], 0, 4) : null;
        $died = fn ($k) => isset($people[$k][5]) ? (int) substr($people[$k][5], 0, 4) : null;
        $place = function ($p) use ($places, &$err) {
            if ($p !== null && ! isset($places[$p])) {
                $err[] = "lieu sans coordonnées : {$p}";
            }
        };
        $person = function ($k) use ($people, $friends, $shared, &$err) {
            $ok = isset($people[$k]) || isset($friends[$k])
                || (str_starts_with((string) $k, 'shared:') && isset($shared['people'][substr($k, 7)]));
            if (! $ok) {
                $err[] = "fiche inconnue : {$k}";
            }
        };
        $alive = function ($k, int $year, string $what) use ($born, $died, &$err) {
            if ($born($k) !== null && ($year < $born($k) || ($died($k) && $year > $died($k)))) {
                $err[] = "{$what} hors de la vie de {$k} ({$year})";
            }
        };

        foreach (['circle', 'locale', 'visitor', 'entry'] as $field) {
            if (! isset($d[$field])) {
                $err[] = "champ manquant : {$field}";
            }
        }
        foreach ($people as $k => $p) {
            $place($p[4]);
            $place($p[6]);
            if ($p[5] && $p[5] < $p[3]) {
                $err[] = "décès avant naissance : {$k}";
            }
            foreach ([$p[7], $p[8]] as $parent) {
                if ($parent) {
                    $person($parent);
                    if (isset($people[$parent])) {
                        $gap = (int) substr($p[3], 0, 4) - $born($parent);
                        if ($gap < 15 || $gap > 60) {
                            $err[] = "âge du parent {$parent} à la naissance de {$k} : {$gap}";
                        }
                    }
                }
            }
        }
        foreach ($d['couples'] as $c) {
            $person($c[0]);
            $person($c[1]);
            $place($c[4]);
        }
        foreach ($d['events'] as $i => $e) {
            in_array($e[1], LifeEventController::TYPES, true) || $err[] = "type inconnu #{$i} : {$e[1]}";
            $place($e[4]);
            foreach ((array) $e[0] as $who) {
                $person($who);
                $alive($who, (int) substr($e[3], 0, 4), "moment #{$i}");
            }
        }
        foreach ($d['friends'] as $c) {
            isset($d['accounts'][$c['owner']]) || $err[] = "responsable inconnu : {$c['owner']}";
            foreach ($c['members'] as $m) {
                isset($d['accounts'][$m]) || $err[] = "membre inconnu : {$m}";
            }
            foreach ($c['people'] as $p) {
                $place($p[4]);
            }
        }
        foreach ($d['accounts'] as $a) {
            $person($a['person']);
        }
        isset($d['accounts'][$d['visitor'] ?? '']) || $err[] = 'visiteur sans compte';
        foreach ($d['albums'] as $k => $a) {
            isset($d['accounts'][$a['owner']]) || $err[] = "album {$k} : propriétaire inconnu";
            ($a['circle'] === 'family' || isset($d['friends'][$a['circle']])) || $err[] = "album {$k} : cercle inconnu";
        }
        foreach ($d['photos'] as $k => $p) {
            $place($p['place']);
            isset($d['accounts'][$p['uploader']]) || $err[] = "photo {$k} : envoyeur inconnu";
            ($p['album'] === null || isset($d['albums'][$p['album']])) || $err[] = "photo {$k} : album inconnu";
            foreach ($p['people'] as $who) {
                $person($who);
                $alive($who, (int) substr($p['date'], 0, 4), "photo {$k}");
            }
        }
        $f = $d['freshen'] ?? [];
        foreach ([...array_keys($f['birthdays'] ?? []), ...array_keys($f['memorials'] ?? []), ...array_merge(...array_map(fn ($w) => [$w[0], $w[1]], $f['weddings'] ?? [[]]))] as $k) {
            if ($k !== null) {
                $person($k);
            }
        }
        foreach ($f['recent_albums'] ?? [] as $k) {
            isset($d['albums'][$k]) || $err[] = "recalage : album inconnu {$k}";
        }
        foreach ($f['on_this_day'] ?? [] as $k) {
            isset($d['photos'][$k]) || $err[] = "recalage : photo inconnue {$k}";
        }

        return array_values(array_unique($err));
    }
}
