<?php

namespace App\Rules;

use App\Models\Person;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Remplace `exists:people,id` : la fiche doit exister ET être visible du
 * compte connecté (Person::scopeVisibleTo). Empêche de relier, taguer ou
 * citer une fiche d'un autre cercle en devinant son identifiant.
 */
class VisiblePerson implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! \Illuminate\Support\Str::isUuid($value) || ! Person::visibleTo(auth()->user())->whereKey($value)->exists()) {
            $fail('La personne sélectionnée est introuvable.');
        }
    }
}
