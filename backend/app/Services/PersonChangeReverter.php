<?php

namespace App\Services;

use App\Models\Person;
use App\Models\PersonChange;
use App\Models\PersonRelationship;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Annule une ligne de l'historique de l'arbre (back-office). L'annulation
 * passe par les modèles : elle est elle-même journalisée.
 */
class PersonChangeReverter
{
    public function revert(PersonChange $change, User $by): void
    {
        abort_unless($change->isRevertible(), 422, 'Cette modification ne peut pas être annulée.');

        DB::transaction(function () use ($change, $by) {
            $person = Person::withTrashed()->find($change->person_id);
            abort_unless($person, 404, 'La fiche n\'existe plus.');

            match ($change->action) {
                'updated' => $person->update(
                    collect($change->diff)->map(fn ($pair) => $pair[0] ?? null)->all()
                ),
                'created' => $person->delete(),
                'deleted' => $person->restore(),
                'spouse_added' => $this->spouses($person->id, $change->diff['spouse_id'])->get()->each->delete(),
                'spouse_removed' => $this->restoreSpouse($person->id, $change->diff['spouse_id']),
            };

            $this->markReverted($change, $by);

            // Les changements de couple sont journalisés sur les deux fiches :
            // on clôt aussi la ligne jumelle.
            if (in_array($change->action, ['spouse_added', 'spouse_removed'], true)) {
                PersonChange::where('person_id', $change->diff['spouse_id'])
                    ->where('action', $change->action)
                    ->whereNull('reverted_at')
                    ->where('created_at', $change->created_at)
                    ->get()
                    ->filter(fn (PersonChange $twin) => ($twin->diff['spouse_id'] ?? null) === $change->person_id)
                    ->each(fn (PersonChange $twin) => $this->markReverted($twin, $by));
            }
        });
    }

    private function spouses(string $a, string $b)
    {
        $ids = [$a, $b];
        sort($ids);

        return PersonRelationship::where('person1_id', $ids[0])->where('person2_id', $ids[1]);
    }

    private function restoreSpouse(string $a, string $b): void
    {
        $ids = [$a, $b];
        sort($ids);
        PersonRelationship::firstOrCreate(['person1_id' => $ids[0], 'person2_id' => $ids[1], 'type' => 'spouse']);
    }

    private function markReverted(PersonChange $change, User $by): void
    {
        $change->forceFill(['reverted_at' => now(), 'reverted_by' => $by->id])->save();
    }
}
