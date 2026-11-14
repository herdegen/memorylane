<?php

namespace App\Services\Newsletter;

use Carbon\CarbonInterface;

/**
 * Fonctionnalités mises en avant dans la newsletter, une par semaine, sans
 * répétition tant que toutes n'ont pas été montrées au destinataire.
 * Ajouter une entrée ici suffit à la faire entrer dans la rotation.
 */
class FeatureSpotlights
{
    /**
     * Nouveautés du site : jusqu'au jour indiqué, chacun la reçoit d'abord
     * (une fois, en tête de lettre, « Nouveau »), puis elle rejoint la
     * rotation ordinaire.
     *
     * @var array<string, string> clé => dernier jour de mise en avant
     */
    public const NEW = ['histoires' => '2026-11-30', 'carte_temps' => '2026-11-30'];

    /**
     * Les fonctionnalités, textes dans la langue courante.
     *
     * @return array<string, array{title: string, text: string, url: string, cta: string}>
     */
    public static function all(): array
    {
        return [
            'histoires' => [
                'title' => __('Les histoires de famille'),
                'text' => __('Des documents, des souvenirs et des photos réunis en une vraie histoire, chapitre par chapitre, avec ce qu’on sait, ce qu’on suppose et les questions qui restent. Chaque dimanche, la lettre en publie un épisode. Toi aussi, tu peux en raconter une, ou répondre aux questions posées à la famille.'),
                'url' => '/stories',
                'cta' => __('Lire les histoires'),
            ],
            'carte_temps' => [
                'title' => __('La famille dans le temps'),
                'text' => __('Sur la carte, « Dans le temps » fait défiler les années : naissances, mariages, maisons successives et voyages. Lance la lecture et regarde la famille se déplacer, génération après génération.'),
                'url' => '/map?temps=1',
                'cta' => __('Voir la famille dans le temps'),
            ],
            'recit_de_vie' => [
                'title' => __('Le récit de vie'),
                'text' => __('Sur la fiche de quelqu\'un, le bouton Diaporama fait voyager la carte de lieu en lieu et raconte sa vie en photos : naissance, maisons, mariage, métiers…'),
                'url' => '/people',
                'cta' => __('Choisir une fiche'),
            ],
            'lien_parente' => [
                'title' => __('Quel est ton lien exact ?'),
                'text' => __('Petit-cousin, grand-oncle par alliance ? Sur la fiche de n\'importe qui, « Lien de parenté » rejoue le chemin dans l\'arbre, pas à pas.'),
                'url' => '/family-tree',
                'cta' => __('Ouvrir l\'arbre'),
            ],
            'qui_est_ce' => [
                'title' => __('Le jeu « Qui est-ce ? »'),
                'text' => __('Des visages attendent un nom sur les vieilles photos. Une carte à la fois, au téléphone comme sur ordinateur : chaque réponse aide toute la famille.'),
                'url' => '/quests/faces',
                'cta' => __('Jouer'),
            ],
            'memos_vocaux' => [
                'title' => __('Raconte une photo à voix haute'),
                'text' => __('Sur n’importe quelle photo, « Raconter cette photo » enregistre ta voix. Qui était là, ce qui s’est passé : la famille pourra l’écouter.'),
                'url' => '/media',
                'cta' => __('Choisir une photo'),
            ],
            'carte' => [
                'title' => __('Tes souvenirs sur la carte'),
                'text' => __('Toutes les photos géolocalisées, sur une carte : survole un endroit pour voir tout ce qui s’y est passé, d’une génération à l’autre.'),
                'url' => '/map',
                'cta' => __('Ouvrir la carte'),
            ],
            'reseau' => [
                'title' => __('Le réseau de tes proches'),
                'text' => __('Qui apparaît le plus souvent avec qui ? Le réseau dessine les liens tissés par les photos, et sa frise montre comment ils sont nés année après année.'),
                'url' => '/network',
                'cta' => __('Voir le réseau'),
            ],
            'albums_identifies' => [
                'title' => __('Partager un album avec ceux qui y figurent'),
                'text' => __('Dans le partage d\'un album, une case suffit : toutes les personnes identifiées sur ses photos le voient en entier, même celles ajoutées plus tard.'),
                'url' => '/albums',
                'cta' => __('Mes albums'),
            ],
            'albums_intelligents' => [
                'title' => __('Les albums qui se remplissent tout seuls'),
                'text' => __('Un album intelligent suit des critères (une personne, un tag, une période) et accueille automatiquement chaque nouvelle photo qui y correspond.'),
                'url' => '/albums',
                'cta' => __('Créer un album'),
            ],
            'import_drive' => [
                'title' => __('Tes photos Google Drive, en un clic'),
                'text' => __('Pioche directement dans ton Drive : les photos arrivent en pleine qualité, sans passer par ton ordinateur.'),
                'url' => '/google-drive',
                'cta' => __('Importer depuis Drive'),
            ],
            'telephone' => [
                'title' => __('MemoryLane sur ton téléphone'),
                'text' => __('Ouvre le site sur ton téléphone puis « Ajouter à l’écran d’accueil » : il se comporte comme une vraie app, et tu envoies tes photos en deux gestes.'),
                'url' => '/guide',
                'cta' => __('Voir comment faire'),
            ],
            'mode_tv' => [
                'title' => __('Les souvenirs sur la télévision'),
                'text' => __('Le mode TV affiche photos, arbre et récits de vie sur grand écran, pilotés à la télécommande. Idéal pour un repas de famille.'),
                'url' => '/tv',
                'cta' => __('Découvrir le mode TV'),
            ],
            'recherche' => [
                'title' => __('Tout retrouver, même avec des fautes'),
                'text' => __('La barre de recherche trouve personnes, albums et photos sans accents ni orthographe exacte : « gael » trouve Gaël, « noel evian » trouve « Noël à Évian ».'),
                'url' => '/dashboard',
                'cta' => __('Essayer'),
            ],
        ];
    }

    /**
     * Fonctionnalités mises en avant dans la lettre AMIS : ni arbre, ni
     * généalogie, ni « famille » dans les textes — ce que vit un cercle
     * d'amis (photos du cercle, souvenirs ensemble, réseau, carte…).
     *
     * @return array<string, array{title: string, text: string, url: string, cta: string}>
     */
    public static function friends(): array
    {
        // Textes déjà neutres de la liste famille, repris tels quels.
        $neutral = array_intersect_key(self::all(), array_flip(['albums_identifies', 'import_drive', 'telephone', 'recherche']));

        return [
            'souvenirs_ensemble' => [
                'title' => __('Vos souvenirs ensemble'),
                'text' => __('Sur la fiche d’un ami, retrouve toutes les photos où vous êtes tous les deux, de la première à la dernière, et raconte comment vous vous êtes connus.'),
                'url' => '/people',
                'cta' => __('Voir mes amis'),
            ],
            'qui_est_ce' => [
                'title' => __('Le jeu « Qui est-ce ? »'),
                'text' => __('Des visages attendent un nom sur les photos du cercle. Une carte à la fois, au téléphone comme sur ordinateur : chaque réponse aide tout le groupe.'),
                'url' => '/quests/faces',
                'cta' => __('Jouer'),
            ],
            'partage_cercle' => [
                'title' => __('Partager tes photos avec le cercle'),
                'text' => __('Dans la galerie, sélectionne quelques photos et partage-les avec le cercle : tous ses membres les voient aussitôt, et elles figureront dans la prochaine lettre.'),
                'url' => '/media',
                'cta' => __('Choisir des photos'),
            ],
            'memos_vocaux' => [
                'title' => __('Raconte une photo à voix haute'),
                'text' => __('Sur n’importe quelle photo, « Raconter cette photo » enregistre ta voix : qui était là, ce qui s’est passé. Tes amis pourront l’écouter.'),
                'url' => '/media',
                'cta' => __('Choisir une photo'),
            ],
            'reseau' => [
                'title' => __('Le réseau de tes amis'),
                'text' => __('Qui apparaît le plus souvent avec qui ? Le réseau dessine les liens tissés par les photos du cercle, et sa frise montre comment ils sont nés au fil des années.'),
                'url' => '/network',
                'cta' => __('Voir le réseau'),
            ],
            'carte' => [
                'title' => __('Tes souvenirs sur la carte'),
                'text' => __('Voyages, week-ends, soirées : les photos géolocalisées du cercle sur une carte. Survole un endroit pour revoir tout ce qui s’y est passé.'),
                'url' => '/map',
                'cta' => __('Ouvrir la carte'),
            ],
        ] + $neutral;
    }

    /**
     * Nouveauté encore mise en avant et jamais montrée à ce destinataire.
     *
     * @param  list<string>  $shown  clés déjà montrées
     * @param  list<string>  $keys  liste à parcourir
     */
    public static function newFor(array $shown, CarbonInterface $today, array $keys): ?string
    {
        foreach (self::NEW as $key => $until) {
            if (in_array($key, $keys, true) && ! in_array($key, $shown, true) && $today->toDateString() <= $until) {
                return $key;
            }
        }

        return null;
    }

    /**
     * Prochaine fonctionnalité pour ce destinataire : la première jamais
     * montrée (ordre mélangé par $seed), sinon la plus anciennement montrée.
     *
     * @param  list<string>  $shownRecentFirst  clés déjà montrées, la plus récente d'abord
     * @param  list<string>|null  $keys  liste à parcourir (défaut : celle de la lettre famille)
     */
    public static function next(array $shownRecentFirst, int $seed, ?array $keys = null): string
    {
        $keys ??= array_keys(self::all());
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
