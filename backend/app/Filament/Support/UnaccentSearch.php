<?php

namespace App\Filament\Support;

use Closure;
use Illuminate\Database\Eloquent\Builder;

/**
 * Recherche sans accents dans l'admin (« gael » → « Gaël ») : la recherche
 * Filament par défaut (ilike côté SQL, includes() côté navigateur pour les
 * listes préchargées) est sensible aux accents.
 */
class UnaccentSearch
{
    /** Pour ->searchable(query: …) d'une colonne de table. */
    public static function columns(array $columns): Closure
    {
        return fn (Builder $query, string $search): Builder => $query->whereUnaccentLike($columns, $search);
    }

    /** Idem sur une relation (« user.name », « person.name »…). */
    public static function relation(string $relation, array $columns): Closure
    {
        return fn (Builder $query, string $search): Builder => $query->whereHas(
            $relation,
            fn (Builder $q) => $q->whereUnaccentLike($columns, $search),
        );
    }

    /**
     * Pour ->getSearchResultsUsing(…) d'un Select : recherche côté serveur.
     *
     * @param  Closure(): Builder  $base  requête des options possibles
     */
    public static function options(Closure $base, array $columns, string $label = 'name', int $limit = 50): Closure
    {
        return fn (string $search): array => $base()
            ->whereUnaccentLike($columns, $search)
            ->orderBy($label)
            ->limit($limit)
            ->pluck($label, $base()->getModel()->getQualifiedKeyName())
            ->all();
    }
}
