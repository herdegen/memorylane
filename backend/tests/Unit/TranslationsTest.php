<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Toute phrase de l'interface passée par t()/$t()/tc()/<Trans> (Vue) ou
 * __()/trans_choice() (PHP) doit avoir sa traduction anglaise dans
 * lang/en.json — le texte français sert de clé. Un oubli afficherait du
 * français à un membre anglophone.
 *
 * Ajouter une phrase : l'écrire en français dans le code via ces fonctions,
 * puis ajouter "phrase française": "English sentence" dans lang/en.json
 * (`node scripts/i18n-check.mjs` liste les manquantes).
 */
class TranslationsTest extends TestCase
{
    private const CALL = '/(?<![\w$.>:])(\$tc|\$t|tc|t|__|trans_choice)\(\s*([\'"`])((?:\\\\.|(?!\2)[^\\\\])*)\2/s';

    private function base(): string
    {
        return dirname(__DIR__, 2);
    }

    /** @return array<string,string> clé → fichier:ligne */
    private function usedKeys(): array
    {
        $files = [];
        foreach (['resources/js', 'resources/views', 'app'] as $dir) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->base().'/'.$dir, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $file) {
                $path = $file->getPathname();
                if (preg_match('#/app/(Filament|Console)/|/views/filament/#', $path)) {
                    continue;
                }
                if (preg_match('/\.(vue|js)$/', $path) || str_ends_with($path, '.php')) {
                    $files[] = $path;
                }
            }
        }

        $keys = [];
        foreach ($files as $path) {
            // Commentaires blanchis (lignes « // », « * », « /* », « # » et
            // blocs <!-- -->) : les exemples de documentation ne comptent pas.
            $src = preg_replace_callback('/<!--[\s\S]*?-->/', fn ($c) => preg_replace('/[^\n]/', ' ', $c[0]), file_get_contents($path));
            $src = preg_replace_callback('/^[ \t]*(\/\/|\/\*|\*|#(?!\[)).*$/m', fn ($c) => str_repeat(' ', strlen($c[0])), $src);
            $php = str_ends_with($path, '.php');
            preg_match_all(self::CALL, $src, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
            foreach ($m as $match) {
                $fn = $match[1][0];
                $quote = $match[2][0];
                $raw = $match[3][0];
                if ($php && ! in_array($fn, ['__', 'trans_choice'], true)) {
                    continue;
                }
                if ($quote === '`' && str_contains($raw, '${')) {
                    continue;
                }
                $key = $php && $quote === "'" ? str_replace(["\\'", '\\\\'], ["'", '\\'], $raw) : $this->unescapeJs($raw);
                $keys[$key] ??= substr($path, strlen($this->base()) + 1).':'.(substr_count(substr($src, 0, $match[0][1]), "\n") + 1);
            }
            if (str_ends_with($path, '.vue')) {
                preg_match_all('/<Trans\b[^>]*?\stext="([^"]*)"/s', $src, $t, PREG_OFFSET_CAPTURE);
                foreach ($t[1] as [$text, $offset]) {
                    $key = html_entity_decode($text, ENT_QUOTES);
                    $keys[$key] ??= substr($path, strlen($this->base()) + 1).':'.(substr_count(substr($src, 0, $offset), "\n") + 1);
                }
            }
        }

        return $keys;
    }

    private function unescapeJs(string $s): string
    {
        return preg_replace_callback('/\\\\(u\{?[0-9a-fA-F]+\}?|x[0-9a-fA-F]{2}|.)/', function ($m) {
            $c = $m[1];
            if ($c[0] === 'u' && strlen($c) > 1) {
                return mb_chr(hexdec(trim(substr($c, 1), '{}')), 'UTF-8');
            }
            if ($c[0] === 'x' && strlen($c) === 3) {
                return chr(hexdec(substr($c, 1)));
            }

            return ['n' => "\n", 't' => "\t", 'r' => "\r"][$c] ?? $c;
        }, $s);
    }

    /** @return array<string,string> */
    private function dictionary(): array
    {
        $json = json_decode(file_get_contents($this->base().'/lang/en.json'), true);
        $this->assertIsArray($json, 'lang/en.json invalide');

        return $json;
    }

    public function test_chaque_phrase_de_l_interface_a_sa_traduction_anglaise(): void
    {
        $dict = $this->dictionary();
        $missing = [];
        foreach ($this->usedKeys() as $key => $where) {
            if (! array_key_exists($key, $dict)) {
                $missing[] = "{$where}  ".json_encode($key, JSON_UNESCAPED_UNICODE);
            }
        }

        $this->assertSame([], $missing, count($missing)." phrase(s) sans traduction dans lang/en.json :\n".implode("\n", array_slice($missing, 0, 40)));
    }

    public function test_les_traductions_gardent_les_memes_parametres(): void
    {
        $params = function (string $s, bool $plural, array $known = []): array {
            preg_match_all('/:([A-Za-z_][A-Za-z0-9_]*)/', $s, $m);
            $names = array_map('strtolower', $m[1]);
            // « :decades » vaut « :decade » suivi de « s » (Laravel remplace
            // le plus long paramètre connu).
            if ($known) {
                $names = array_map(function ($p) use ($known) {
                    $prefixes = array_filter($known, fn ($k) => str_starts_with($p, $k));
                    usort($prefixes, fn ($x, $y) => strlen($y) <=> strlen($x));

                    return $prefixes[0] ?? $p;
                }, $names);
            }
            $names = array_values(array_unique(array_filter($names, fn ($p) => ! ($plural && $p === 'count'))));
            sort($names);

            return $names;
        };

        $wrong = [];
        foreach ($this->dictionary() as $key => $value) {
            $plural = str_contains($key, '|');
            $expected = $params($key, $plural);
            if ($expected !== $params((string) $value, $plural, $params($key, false))) {
                $wrong[] = json_encode($key, JSON_UNESCAPED_UNICODE).' → '.json_encode($value, JSON_UNESCAPED_UNICODE);
            }
            // Une traduction au pluriel pour une clé qui ne l'est pas
            // s'afficherait telle quelle (« a|b »). L'inverse est permis
            // (« :count selected » convient à tous les nombres).
            if (! $plural && str_contains((string) $value, '|')) {
                $wrong[] = 'pluriel : '.json_encode($key, JSON_UNESCAPED_UNICODE);
            }
        }

        $this->assertSame([], $wrong, implode("\n", array_slice($wrong, 0, 40)));
    }
}
