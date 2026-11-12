<?php

/*
 * Ancêtres communs aux deux familles de la démo : les Arnaud de
 * Barcelonnette (vallée de l'Ubaye). Leur fils Joseph est resté en France
 * (famille Lambert, fr.php) ; son frère Honoré est parti pour le Mexique en
 * 1861, comme des milliers de « Barcelonnettes » (famille Sullivan, us.php).
 * Une fiche commune : rattachée aux deux familles, chacune n'y voit que sa
 * propre descendance. Clés référencées par « shared:<clé> ».
 */
return [
    'places' => [
        'Barcelonnette, France' => [44.387, 6.652],
        'Jausiers, France' => [44.417, 6.73],
    ],

    // clé => [prénom, nom, genre, naissance, lieu, décès, lieu, père, mère, nom de naissance]
    'people' => [
        'jb_arnaud' => ['Jean-Baptiste', 'Arnaud', 'M', '1808-03-19', 'Barcelonnette, France', '1879-11-02', 'Barcelonnette, France', null, null, null],
        'marie_rose' => ['Marie-Rose', 'Arnaud', 'F', '1812-06-02', 'Jausiers, France', '1890-01-14', 'Barcelonnette, France', null, null, 'Signoret'],
    ],

    'couples' => [
        ['jb_arnaud', 'marie_rose', 'spouse', '1834-04-21', 'Barcelonnette, France', null],
    ],

    'events' => [
        ['jb_arnaud', 'job', 'Marchand drapier sur la place de Barcelonnette', '1832', 'Barcelonnette, France', null, null],
        ['jb_arnaud', 'residence', 'Maison de la rue Manuel', '1834', 'Barcelonnette, France', null, null],
    ],

    // Correction proposée par la famille non référente (calculée à la construction).
    'proposals' => [
        [
            'person' => 'jb_arnaud',
            'fields' => ['death_date' => '1879-11-12', 'death_place' => 'Jausiers, France'],
            'source' => [
                'fr' => 'Acte de décès n° 41, Jausiers, 1879 (Archives des Alpes-de-Haute-Provence, état civil en ligne, vue 23).',
                'en' => 'Death record no. 41, Jausiers, 1879 (Alpes-de-Haute-Provence archives, online civil records, image 23).',
            ],
            'message' => [
                'fr' => 'En cherchant Honoré, nous sommes tombés sur l’acte : Jean-Baptiste serait mort chez sa fille, à Jausiers, dix jours plus tard. Vous confirmez ?',
                'en' => 'While looking for Honoré we found this record: Jean-Baptiste seems to have died at his daughter’s in Jausiers, ten days later. Can you confirm?',
            ],
        ],
    ],
];

