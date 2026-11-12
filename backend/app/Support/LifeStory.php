<?php

namespace App\Support;

use App\Models\Person;

/**
 * Récit de vie guidé : les étapes, de la naissance à aujourd'hui. Chaque
 * étape propose des éléments datés qui vont droit sur la frise (écoles,
 * métiers, lieux de vie…) et un récit libre, écrit ou à l'oral.
 */
class LifeStory
{
    /**
     * Éléments datés : libellés du formulaire, titre par défaut, période
     * (de… à…) ou année seule. « place_only » : un seul champ, le lieu.
     */
    public static function rowTypes(): array
    {
        return [
            'residence' => ['add' => __('Ajouter un lieu de vie'), 'place_label' => __('Ville ou adresse'), 'range' => true, 'place_only' => true],
            'bapteme' => ['add' => __('Ajouter un baptême'), 'title' => __('Baptême'), 'place_label' => __('Église, ville'), 'range' => false],
            'communion' => ['add' => __('Ajouter une communion'), 'title' => __('Communion'), 'place_label' => __('Église, ville'), 'range' => false],
            'education' => ['add' => __('Ajouter un établissement'), 'title_label' => __('Établissement'), 'place_label' => __('Ville'), 'range' => true],
            'diplome' => ['add' => __('Ajouter un diplôme'), 'title_label' => __('Diplôme'), 'place_label' => __('Ville'), 'range' => false],
            'military' => ['add' => __('Ajouter une affectation'), 'title_label' => __('Régiment, caserne'), 'place_label' => __('Ville'), 'range' => true],
            'job' => ['add' => __('Ajouter un emploi'), 'title_label' => __('Métier, employeur'), 'place_label' => __('Ville'), 'range' => true],
            'fiancailles' => ['add' => __('Ajouter des fiançailles'), 'title' => __('Fiançailles'), 'place_label' => __('Lieu'), 'range' => false],
            'mariage' => ['add' => __('Ajouter un mariage'), 'title_label' => __('Mariage (avec qui)'), 'place_label' => __('Lieu'), 'range' => false],
            'voyage' => ['add' => __('Ajouter un voyage'), 'title_label' => __('Destination'), 'place_label' => __('Lieu'), 'range' => false],
            'moment' => ['add' => __('Ajouter un événement'), 'title_label' => __('Événement'), 'place_label' => __('Lieu'), 'range' => false],
        ];
    }

    /**
     * Étapes : clé, titre, âges (pour suggérer les années), éléments datés,
     * question des déménagements, pistes pour le récit.
     *
     * @return list<array>
     */
    public static function chapters(Person $person): array
    {
        $deceased = $person->death_date !== null;
        $moves = __('Un déménagement pendant ces années-là ?');

        return [
            ['key' => 'naissance', 'title' => __('Naissance et petite enfance'), 'ages' => [0, 6], 'rows' => ['bapteme'],
                'moves' => __('Où vivait la famille ? Des déménagements ?'),
                'prompts' => [__('La maison de l’enfance, le quartier'), __('Les premiers souvenirs'), __('Les frères et sœurs, les grands-parents')]],
            ['key' => 'primaire', 'title' => __('L’école primaire'), 'ages' => [6, 11], 'rows' => ['education', 'communion'], 'suggest' => 'education',
                'moves' => $moves,
                'prompts' => [__('La maîtresse ou le maître'), __('Les copains, les jeux de la récré'), __('Le chemin de l’école')]],
            ['key' => 'college', 'title' => __('Le collège'), 'ages' => [11, 15], 'rows' => ['education'], 'suggest' => 'education',
                'moves' => $moves,
                'prompts' => [__('Les matières préférées, les professeurs marquants'), __('Les amis'), __('Les vacances')]],
            ['key' => 'lycee', 'title' => __('Le lycée'), 'ages' => [15, 18], 'rows' => ['education', 'diplome'], 'suggest' => 'education',
                'moves' => $moves,
                'prompts' => [__('La filière, le bac'), __('Les sorties, la musique de l’époque'), __('Les premières vacances sans les parents')]],
            ['key' => 'etudes', 'title' => __('Études ou apprentissage'), 'ages' => [18, 25], 'rows' => ['education', 'diplome'], 'optional' => true,
                'moves' => __('Un logement d’étudiant, un premier appartement ?'),
                'prompts' => [__('Le choix des études ou du métier'), __('La vie d’étudiant ou d’apprenti'), __('Les rencontres de ces années-là')]],
            ['key' => 'service', 'title' => __('Le service militaire'), 'ages' => [18, 22], 'rows' => ['military'], 'optional' => true,
                'moves' => null,
                'prompts' => [__('Où, combien de temps'), __('Les souvenirs de caserne, les camarades')]],
            ['key' => 'travail', 'title' => __('La vie professionnelle'), 'ages' => [18, 65], 'rows' => ['job'],
                'moves' => __('Des déménagements pour le travail ?'),
                'prompts' => [__('Le premier emploi, le premier salaire'), __('Les collègues, les patrons'), __('Le métier qu’on a le plus aimé')]],
            ['key' => 'couple', 'title' => __('La rencontre et le mariage'), 'ages' => [18, 40], 'rows' => ['fiancailles', 'mariage'],
                'moves' => __('Les lieux de vie en couple ?'),
                'prompts' => [__('Où, quand et comment la rencontre a eu lieu'), __('La demande, le mariage, la fête'), __('Le voyage de noces')]],
            ['key' => 'enfants', 'title' => __('Les enfants'), 'ages' => [20, 45], 'rows' => [], 'children' => true,
                'moves' => __('Où vivait la famille à leurs naissances ? Des déménagements ?'),
                'prompts' => [__('Les naissances'), __('La vie de famille, les vacances'), __('Les fêtes et les traditions')]],
            ['key' => 'voyages', 'title' => __('Voyages et grands moments'), 'ages' => null, 'rows' => ['voyage', 'moment'],
                'moves' => null,
                'prompts' => [__('Un voyage inoubliable'), __('Un événement historique vécu'), __('Une fête mémorable')]],
            ['key' => 'aujourdhui', 'title' => $deceased ? __('Les dernières années') : __('La retraite et aujourd’hui'), 'ages' => [60, null], 'rows' => ['moment'],
                'moves' => $deceased ? __('Les derniers lieux de vie ?') : __('Le lieu de vie aujourd’hui, et depuis quand ?'),
                'prompts' => [__('La retraite, les passions'), __('Les petits-enfants'), __('Ce qu’il faudrait transmettre')]],
        ];
    }

    public static function chapter(Person $person, string $key): ?array
    {
        return collect(self::chapters($person))->firstWhere('key', $key);
    }

    /** Types d'éléments datés acceptés pour une étape. */
    public static function allowedTypes(array $chapter): array
    {
        return array_values(array_unique([...$chapter['rows'], ...($chapter['moves'] ? ['residence'] : [])]));
    }
}
