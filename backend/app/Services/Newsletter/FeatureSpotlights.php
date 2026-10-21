<?php

namespace App\Services\Newsletter;

/**
 * Fonctionnalités mises en avant dans la newsletter, une par semaine, sans
 * répétition tant que toutes n'ont pas été montrées au destinataire.
 * Ajouter une entrée ici suffit à la faire entrer dans la rotation.
 */
class FeatureSpotlights
{
    /** @var array<string, array{title: string, text: string, url: string, cta: string}> */
    public const ALL = [
        'recit_de_vie' => [
            'title' => 'Le récit de vie',
            'text' => 'Sur la fiche de quelqu\'un, le bouton Diaporama fait voyager la carte de lieu en lieu et raconte sa vie en photos : naissance, maisons, mariage, métiers…',
            'url' => '/people',
            'cta' => 'Choisir une fiche',
        ],
        'lien_parente' => [
            'title' => 'Quel est votre lien exact ?',
            'text' => 'Petit-cousin, grand-oncle par alliance ? Sur la fiche de n\'importe qui, « Lien de parenté » rejoue le chemin dans l\'arbre, pas à pas.',
            'url' => '/family-tree',
            'cta' => 'Ouvrir l\'arbre',
        ],
        'qui_est_ce' => [
            'title' => 'Le jeu « Qui est-ce ? »',
            'text' => 'Des visages attendent un nom sur les vieilles photos. Une carte à la fois, au téléphone comme sur ordinateur : chaque réponse aide toute la famille.',
            'url' => '/quests/faces',
            'cta' => 'Jouer',
        ],
        'memos_vocaux' => [
            'title' => 'Racontez une photo à voix haute',
            'text' => 'Sur n\'importe quelle photo, « Raconter cette photo » enregistre votre voix. Qui était là, ce qui s\'est passé : la famille pourra l\'écouter.',
            'url' => '/media',
            'cta' => 'Choisir une photo',
        ],
        'carte' => [
            'title' => 'Vos souvenirs sur la carte',
            'text' => 'Toutes les photos géolocalisées, sur une carte : survolez un endroit pour voir tout ce qui s\'y est passé, d\'une génération à l\'autre.',
            'url' => '/map',
            'cta' => 'Ouvrir la carte',
        ],
        'reseau' => [
            'title' => 'Le réseau de vos proches',
            'text' => 'Qui apparaît le plus souvent avec qui ? Le réseau dessine les liens tissés par les photos, et sa frise montre comment ils sont nés année après année.',
            'url' => '/network',
            'cta' => 'Voir le réseau',
        ],
        'albums_identifies' => [
            'title' => 'Partager un album avec ceux qui y figurent',
            'text' => 'Dans le partage d\'un album, une case suffit : toutes les personnes identifiées sur ses photos le voient en entier, même celles ajoutées plus tard.',
            'url' => '/albums',
            'cta' => 'Mes albums',
        ],
        'albums_intelligents' => [
            'title' => 'Les albums qui se remplissent tout seuls',
            'text' => 'Un album intelligent suit des critères (une personne, un tag, une période) et accueille automatiquement chaque nouvelle photo qui y correspond.',
            'url' => '/albums',
            'cta' => 'Créer un album',
        ],
        'import_drive' => [
            'title' => 'Vos photos Google Drive, en un clic',
            'text' => 'Piochez directement dans votre Drive : les photos arrivent en pleine qualité, sans passer par votre ordinateur.',
            'url' => '/google-drive',
            'cta' => 'Importer depuis Drive',
        ],
        'telephone' => [
            'title' => 'MemoryLane sur votre téléphone',
            'text' => 'Ouvrez le site sur votre téléphone puis « Ajouter à l\'écran d\'accueil » : il se comporte comme une vraie app, et vous envoyez vos photos en deux gestes.',
            'url' => '/guide',
            'cta' => 'Voir comment faire',
        ],
        'mode_tv' => [
            'title' => 'Les souvenirs sur la télévision',
            'text' => 'Le mode TV affiche photos, arbre et récits de vie sur grand écran, pilotés à la télécommande. Idéal pour un repas de famille.',
            'url' => '/tv',
            'cta' => 'Découvrir le mode TV',
        ],
        'recherche' => [
            'title' => 'Tout retrouver, même avec des fautes',
            'text' => 'La barre de recherche trouve personnes, albums et photos sans accents ni orthographe exacte : « gael » trouve Gaël, « noel evian » trouve « Noël à Évian ».',
            'url' => '/dashboard',
            'cta' => 'Essayer',
        ],
    ];

    /**
     * Prochaine fonctionnalité pour ce destinataire : la première jamais
     * montrée (ordre mélangé par $seed), sinon la plus anciennement montrée.
     *
     * @param  list<string>  $shownRecentFirst  clés déjà montrées, la plus récente d'abord
     */
    public static function next(array $shownRecentFirst, int $seed): string
    {
        $keys = array_keys(self::ALL);
        mt_srand($seed);
        shuffle($keys);
        mt_srand();

        foreach ($keys as $key) {
            if (! in_array($key, $shownRecentFirst, true)) {
                return $key;
            }
        }

        // Tout a été vu : on reprend la plus anciennement montrée.
        $known = array_values(array_intersect($shownRecentFirst, $keys));

        return end($known) ?: $keys[0];
    }
}
