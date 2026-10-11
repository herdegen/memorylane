<?php

return [
    /*
    | Mode par défaut de l'installation : « family » (arbre généalogique,
    | fiches ouvertes à toute la famille) ou « friends » (réseau tissé par les
    | photos, fiches visibles du seul cercle). Fixé à l'installation ; l'admin
    | peut ensuite créer des cercles de l'un ou l'autre type.
    */
    'default_mode' => env('MEMORYLANE_MODE', 'family') === 'friends' ? 'friends' : 'family',

    // Reconnaissance de visages.
    'faces' => [
        // Association automatique d'un visage à une personne (sans humain).
        // Coupée tant que le moteur face-api se trompait (38 % d'erreurs
        // mesurées) ; réactivée avec InsightFace et des seuils stricts.
        'auto_match' => (bool) env('FACES_AUTO_MATCH', false),
    ],
];
