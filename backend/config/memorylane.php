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

        // Moteur : 'faceapi' (navigateur, empreintes 128 dans `embedding`) ou
        // 'insightface' (service docker/faces, empreintes 512 normées dans
        // `embedding_v2`). Les deux colonnes coexistent : retour arrière par
        // simple réglage.
        'engine' => env('FACES_ENGINE', 'faceapi') === 'insightface' ? 'insightface' : 'faceapi',
        'service_url' => env('FACES_SERVICE_URL', 'http://faces:8000'),

        // Seuils (distance euclidienne) calibrés sur nos photos (banc d'essai
        // du 2026-10-11). InsightFace : vecteurs normés, distance 0..2.
        'thresholds' => [
            'faceapi' => ['match' => 0.6, 'auto' => 0.45, 'margin' => 0.0],
            // 'strong' : distance affichée comme 100 % de ressemblance.
            'insightface' => ['match' => 1.25, 'auto' => 1.0, 'margin' => 0.15, 'strong' => 0.75],
        ],
    ],
];
