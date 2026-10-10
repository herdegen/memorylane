<?php

namespace App\Observers;

use App\Models\Person;
use App\Models\PersonChange;

/**
 * Historique de l'arbre : chaque modification faite depuis l'application
 * (compte connecté, y compris back-office et quêtes) est journalisée pour
 * pouvoir être relue et annulée. Les commandes console (imports GEDCOM…)
 * ne journalisent pas.
 */
class PersonObserver
{
    private const DATE_FIELDS = ['birth_date', 'death_date'];

    public function created(Person $person): void
    {
        $this->log($person, 'created', ['name' => $person->name]);
    }

    public function updated(Person $person): void
    {
        $diff = [];
        foreach (array_keys(PersonChange::FIELD_LABELS) as $field) {
            if (! array_key_exists($field, $person->getChanges())) {
                continue;
            }
            $old = $this->normalize($field, $person->getRawOriginal($field));
            $new = $this->normalize($field, $person->getAttributes()[$field] ?? null);
            if ($old !== $new) {
                $diff[$field] = [$old, $new];
            }
        }

        if ($diff !== []) {
            $this->log($person, 'updated', $diff);
        }
    }

    public function deleted(Person $person): void
    {
        if (! $person->isForceDeleting()) {
            $this->log($person, 'deleted', ['name' => $person->name]);
        }
    }

    public function restored(Person $person): void
    {
        $this->log($person, 'restored', ['name' => $person->name]);
    }

    private function normalize(string $field, mixed $value): mixed
    {
        if ($value === '' || $value === null) {
            return null;
        }

        if (in_array($field, self::DATE_FIELDS, true)) {
            return substr((string) $value, 0, 10);
        }

        return is_scalar($value) ? (string) $value : $value;
    }

    private function log(Person $person, string $action, array $changes): void
    {
        $userId = auth()->id();
        if ($userId === null) {
            return;
        }

        PersonChange::create([
            'person_id' => $person->id,
            'user_id' => $userId,
            'action' => $action,
            'diff' => $changes,
        ]);
    }
}
