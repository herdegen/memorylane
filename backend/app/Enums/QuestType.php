<?php

namespace App\Enums;

use App\Models\DetectedFace;
use App\Models\Media;
use App\Models\Person;

/**
 * Types de questions de la gamification « quêtes » : chaque type sait détecter
 * son manque (QuestService), valider la réponse saisie et se faire appliquer
 * (QuestAnswerApplier). La valeur string est celle stockée dans
 * quest_answers.question_type et échangée avec le front.
 */
enum QuestType: string
{
    case BirthDate = 'birth_date';
    case BirthPlace = 'birth_place';
    case DeathStatus = 'death_status';      // 70-120 ans : « encore en vie ? »
    case DeathDateOld = 'death_date_old';   // > 120 ans (GEDCOM) : date de décès
    case DeathPlace = 'death_place';
    case Gender = 'gender';
    case ParentFather = 'parent_father';
    case ParentMother = 'parent_mother';
    case MaritalStatus = 'marital_status';
    case MaidenName = 'maiden_name';
    case Job = 'job';
    case Education = 'education';
    case Residence = 'residence';
    case FaceIdentify = 'face_identify';
    case MediaDate = 'media_date';
    case MediaGeo = 'media_geo';

    /** Classe Eloquent du sujet de la question. */
    public function subjectClass(): string
    {
        return match ($this) {
            self::FaceIdentify => DetectedFace::class,
            self::MediaDate, self::MediaGeo => Media::class,
            default => Person::class,
        };
    }

    /** Clé canonique de la question pour un sujet donné. */
    public function key(string $subjectId): string
    {
        return $this->value.':'.$subjectId;
    }

    /**
     * Ce type accepte-t-il la réponse « non » ? (« en vie », « jamais marié »,
     * « parent inconnu », « pas d'études supérieures », « pas un visage »…)
     */
    public function allowsNo(): bool
    {
        return match ($this) {
            self::DeathStatus, self::MaritalStatus, self::ParentFather,
            self::ParentMother, self::MaidenName, self::Education,
            self::FaceIdentify => true,
            default => false,
        };
    }

    /**
     * Durée (jours) pendant laquelle un « non » éteint la question pour tout
     * le monde. Null = définitif. Les situations évolutives (encore en vie,
     * jamais marié) redeviennent posables au bout d'un an.
     */
    public function noAnswerTtlDays(): ?int
    {
        return match ($this) {
            self::DeathStatus, self::MaritalStatus => 365,
            default => null,
        };
    }

    /** Règles de validation du payload d'une réponse « answered ». */
    public function rules(): array
    {
        return match ($this) {
            self::BirthDate => ['value' => 'required|date|before:tomorrow'],
            self::DeathDateOld => ['value' => 'required|date|before:tomorrow'],
            self::DeathStatus => ['death_date' => 'nullable|date|before:tomorrow'],
            self::BirthPlace, self::DeathPlace, self::MaidenName => ['value' => 'required|string|max:255'],
            self::Gender => ['value' => 'required|in:M,F'],
            self::ParentFather, self::ParentMother => ['parent_id' => 'required|uuid|exists:people,id'],
            self::MaritalStatus => [
                'spouse_id' => 'required|uuid|exists:people,id',
                'type' => 'nullable|in:spouse,partner',
                'year' => 'nullable|integer|between:1500,'.now()->year,
            ],
            self::Job, self::Education => [
                'title' => 'required|string|max:255',
                'year' => 'required|integer|between:1500,'.now()->year,
            ],
            self::Residence => [
                'place' => 'required|string|max:255',
                'year' => 'required|integer|between:1500,'.now()->year,
            ],
            self::FaceIdentify => ['person_id' => 'required|uuid|exists:people,id'],
            self::MediaDate => ['value' => 'required|date|before:tomorrow'],
            self::MediaGeo => [
                'latitude' => 'required|numeric|between:-90,90',
                'longitude' => 'required|numeric|between:-180,180',
            ],
        };
    }
}
