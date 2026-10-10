<?php

namespace App\Observers;

use App\Models\Person;
use App\Models\PersonChange;
use App\Models\PersonRelationship;

/**
 * Historique de l'arbre, côté couples : l'ajout ou le retrait d'un conjoint
 * est journalisé sur les deux fiches.
 */
class PersonRelationshipObserver
{
    public function created(PersonRelationship $relationship): void
    {
        $this->log($relationship, 'spouse_added');
    }

    public function deleted(PersonRelationship $relationship): void
    {
        $this->log($relationship, 'spouse_removed');
    }

    private function log(PersonRelationship $relationship, string $action): void
    {
        $userId = auth()->id();
        if ($userId === null) {
            return;
        }

        $pair = [$relationship->person1_id, $relationship->person2_id];
        $names = Person::withTrashed()->whereIn('id', $pair)->pluck('name', 'id');

        foreach ([$pair, array_reverse($pair)] as [$self, $other]) {
            PersonChange::create([
                'person_id' => $self,
                'user_id' => $userId,
                'action' => $action,
                'diff' => ['spouse_id' => $other, 'spouse_name' => $names[$other] ?? null],
            ]);
        }
    }
}
