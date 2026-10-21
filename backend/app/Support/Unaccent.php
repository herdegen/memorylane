<?php

namespace App\Support;

use Illuminate\Contracts\Database\Query\Builder as BuilderContract;
use Illuminate\Support\Str;

/**
 * Recherche « sans accents ni casse » en SQL : « gael » trouve « Gaël »,
 * « eloise » trouve « Éloïse ». Les deux côtés passent par lower() puis
 * translate() (aucune extension PostgreSQL requise).
 *
 * Utilisation : $query->whereUnaccentLike(['first_name', 'last_name'], $q)
 * — chaque mot doit être trouvé (« gael barbier » sur prénom + nom).
 * (macro déclarée dans AppServiceProvider, sur les builders Eloquent et SQL).
 */
class Unaccent
{
    /** Lettres accentuées (minuscules : lower() passe avant) → sans accent, 1 pour 1. */
    private const MAP = [
        'à' => 'a', 'â' => 'a', 'ä' => 'a', 'á' => 'a', 'ã' => 'a', 'å' => 'a', 'ā' => 'a',
        'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'ē' => 'e', 'ė' => 'e', 'ę' => 'e',
        'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i', 'ī' => 'i',
        'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'ö' => 'o', 'õ' => 'o', 'ø' => 'o', 'ō' => 'o',
        'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ū' => 'u',
        'ç' => 'c', 'ć' => 'c', 'č' => 'c', 'ñ' => 'n', 'ń' => 'n',
        'ÿ' => 'y', 'ý' => 'y', 'š' => 's', 'ś' => 's', 'ž' => 'z', 'ź' => 'z', 'ż' => 'z', 'ł' => 'l',
    ];

    /** Expression SQL normalisée d'une colonne (nom de colonne de confiance, pas une saisie). */
    public static function sql(string $column): string
    {
        $from = implode('', array_keys(self::MAP));
        $to = implode('', array_values(self::MAP));

        return "translate(lower({$column}), '{$from}', '{$to}')";
    }

    /** Terme de recherche normalisé, échappé pour LIKE, entouré de %. */
    public static function pattern(string $term): string
    {
        $plain = Str::lower(Str::ascii(trim($term)));

        return '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $plain).'%';
    }

    /**
     * Chaque mot du terme doit apparaître (dans n'importe quelle colonne,
     * n'importe quel ordre) : « noel evian » trouve « Noël à Évian ».
     */
    public static function apply(BuilderContract $query, string|array $columns, string $term, string $boolean = 'and'): BuilderContract
    {
        $words = preg_split('/\s+/u', trim($term), -1, PREG_SPLIT_NO_EMPTY) ?: [''];

        return $query->where(function ($all) use ($columns, $words) {
            foreach ($words as $word) {
                $pattern = self::pattern($word);
                $all->where(function ($any) use ($columns, $pattern) {
                    foreach ((array) $columns as $column) {
                        $any->orWhereRaw(self::sql($column).' like ?', [$pattern]);
                    }
                });
            }
        }, boolean: $boolean);
    }
}
