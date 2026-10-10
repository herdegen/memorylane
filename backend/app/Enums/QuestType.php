<?php

namespace App\Enums;

use App\Rules\VisiblePerson;
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
    case Address = 'address';               // adresse actuelle, foyer uniquement
    case FaceIdentify = 'face_identify';
    case MediaDate = 'media_date';
    case MediaGeo = 'media_geo';
    case HowMet = 'how_met';                // mode amis : « comment as-tu connu X ? »

    /** Classe Eloquent du sujet de la question. */
    public function subjectClass(): string
    {
        return match ($this) {
            self::FaceIdentify => DetectedFace::class,
            self::MediaDate, self::MediaGeo => Media::class,
            default => Person::class,
        };
    }

    /**
     * Question propre à chaque compte (une réponse n'éteint pas la question
     * pour les autres) : « comment as-tu connu X » dépend de qui répond.
     */
    public function perUser(): bool
    {
        return $this === self::HowMet;
    }

    /** Questions de généalogie, absentes du mode amis. */
    public function genealogical(): bool
    {
        return match ($this) {
            self::BirthDate, self::FaceIdentify, self::MediaDate, self::MediaGeo, self::HowMet => false,
            default => true,
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
            self::BirthPlace, self::DeathPlace, self::MaidenName, self::Address => ['value' => 'required|string|max:255'],
            self::Gender => ['value' => 'required|in:M,F'],
            self::ParentFather, self::ParentMother => ['parent_id' => ['required', 'uuid', new VisiblePerson]],
            self::MaritalStatus => [
                'spouse_id' => ['required', 'uuid', new VisiblePerson],
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
            self::FaceIdentify => ['person_id' => ['required', 'uuid', new VisiblePerson]],
            self::MediaDate => ['value' => 'required|date|before:tomorrow'],
            self::MediaGeo => [
                'latitude' => 'required|numeric|between:-90,90',
                'longitude' => 'required|numeric|between:-180,180',
            ],
            self::HowMet => [
                'context' => 'required|in:'.implode(',', array_keys(\App\Models\PersonLink::CONTEXTS)),
                'via_person_id' => ['nullable', 'uuid', new VisiblePerson],
                'story' => 'nullable|string|max:500',
                'year' => 'nullable|integer|between:1900,'.now()->year,
            ],
        };
    }
}
