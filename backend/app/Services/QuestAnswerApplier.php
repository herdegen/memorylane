<?php

namespace App\Services;

use App\Enums\QuestType;
use App\Exceptions\StaleQuestException;
use App\Models\DetectedFace;
use App\Models\LifeEvent;
use App\Models\Media;
use App\Models\Person;
use App\Models\PersonRelationship;
use App\Models\User;
use App\Services\Vision\FaceMatcher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

/**
 * Applique la réponse d'une quête directement dans les données (canal
 * d'écriture collaboratif, comme l'identification de visages) : write-once —
 * on ne remplit que du vide, jamais d'écrasement. Re-vérifie l'autorisation
 * et l'existence du manque au moment d'écrire (anti-course entre comptes).
 */
class QuestAnswerApplier
{
    public function __construct(
        private QuestService $quests,
        private FaceMatcher $faceMatcher,
    ) {
    }

    /**
     * Matrice d'autorisations : ne pas répondre à une question qu'on ne peut
     * pas appliquer. Fiches personnes : tout compte connecté (fiches globales
     * à l'instance, journalisé). Visages : quiconque peut VOIR le média
     * (aligné VisionController::matchFace). Date/géoloc média : owner strict
     * (aligné MediaController::update / MapController::updateGeolocation).
     */
    public function authorize(User $user, QuestType $type, Model $subject): void
    {
        if ($subject instanceof DetectedFace) {
            Gate::forUser($user)->authorize('view', $subject->media);
        } elseif ($subject instanceof Media) {
            abort_unless($subject->user_id === $user->id, 403);
        }
        // Person : fiches lisibles par tout compte connecté ; le remplissage
        // de champs vides est ouvert à tous, tracé dans quest_answers.
    }

    /**
     * Applique une réponse « answered ». Lève StaleQuestException si le
     * manque a été comblé entre-temps (→ 409 côté contrôleur).
     */
    public function apply(User $user, QuestType $type, Model $subject, array $payload): void
    {
        $this->authorize($user, $type, $subject);

        if (! $this->quests->gapExists($type, $subject)) {
            throw new StaleQuestException('Cette information a déjà été complétée.');
        }

        match ($type) {
            QuestType::BirthDate => $subject->update(['birth_date' => $payload['value']]),
            QuestType::BirthPlace => $subject->update(['birth_place' => $payload['value']]),
            QuestType::DeathDateOld => $subject->update(['death_date' => $payload['value']]),
            // « Décédé » : la date est optionnelle (on peut savoir que la
            // personne est morte sans connaître la date).
            QuestType::DeathStatus => empty($payload['death_date'])
                ? null
                : $subject->update(['death_date' => $payload['death_date']]),
            QuestType::DeathPlace => $subject->update(['death_place' => $payload['value']]),
            QuestType::Gender => $subject->update(['gender' => $payload['value']]),
            QuestType::MaidenName => $subject->update(['maiden_name' => $payload['value']]),
            QuestType::ParentFather => $this->applyParent($subject, $payload['parent_id'], 'father'),
            QuestType::ParentMother => $this->applyParent($subject, $payload['parent_id'], 'mother'),
            QuestType::MaritalStatus => $this->applyMarital($subject, $payload),
            QuestType::Job => $this->applyLifeEvent($user, $subject, 'job', $payload['title'], (int) $payload['year']),
            QuestType::Education => $this->applyLifeEvent($user, $subject, 'education', $payload['title'], (int) $payload['year']),
            QuestType::Residence => $this->applyLifeEvent($user, $subject, 'residence', $payload['place'], (int) $payload['year'], $payload['place']),
            QuestType::FaceIdentify => $this->faceMatcher->applyMatch($subject, $payload['person_id']),
            QuestType::MediaDate => $subject->update(['taken_at' => $payload['value']]),
            QuestType::MediaGeo => $subject->metadata()->updateOrCreate([], [
                'latitude' => $payload['latitude'],
                'longitude' => $payload['longitude'],
            ]),
        };
    }

    /**
     * Effets de bord d'un « non » : la plupart n'écrivent rien (journal seul),
     * mais « ce n'est pas un visage » rejette le visage (extinction native).
     */
    public function applyNo(User $user, QuestType $type, Model $subject): void
    {
        $this->authorize($user, $type, $subject);

        if ($type === QuestType::FaceIdentify && $subject->status === 'unmatched') {
            $this->faceMatcher->disassociate($subject, 'dismissed');
        }
    }

    private function applyParent(Person $person, string $parentId, string $slot): void
    {
        abort_if($parentId === $person->id, 422, 'Une personne ne peut pas être son propre parent.');

        $person->update([$slot.'_id' => $parentId]);
    }

    /**
     * Union : mêmes règles que PersonController::addSpouse (ids triés,
     * firstOrCreate). L'année seule ne remplit PAS start_date (les
     * célébrations du Dashboard fêteraient un faux 1ᵉʳ janvier) — elle reste
     * dans le payload journalisé.
     */
    private function applyMarital(Person $person, array $payload): void
    {
        abort_if($payload['spouse_id'] === $person->id, 422, 'Une personne ne peut pas être son propre conjoint.');

        $ids = [$person->id, $payload['spouse_id']];
        sort($ids);

        PersonRelationship::firstOrCreate([
            'person1_id' => $ids[0],
            'person2_id' => $ids[1],
            'type' => $payload['type'] ?? 'spouse',
        ]);
    }

    private function applyLifeEvent(User $user, Person $person, string $type, string $title, int $year, ?string $place = null): void
    {
        LifeEvent::create([
            'person_id' => $person->id,
            'user_id' => $user->id,
            'type' => $type,
            'title' => $title,
            'place' => $place,
            'event_date' => sprintf('%04d-01-01', $year),
        ]);
    }
}
