<?php

/*
 * Site de démonstration (demo.memory-lane.fr) : base et photos à part,
 * famille fictive, compte partagé « standard », remise à zéro chaque nuit.
 * Désactivé partout ailleurs (DEMO_MODE absent).
 */
return [
    'enabled' => (bool) env('DEMO_MODE', false),

    // Compte partagé dans lequel « Entrer dans la démo » connecte.
    'user_email' => env('DEMO_USER_EMAIL', 'demo@memory-lane.fr'),

    // Instantané de la base remis en place chaque nuit (demo:reset).
    'snapshot' => storage_path('app/private/demo/snapshot.dump'),

    /*
     * Actions bloquées : ajout de contenu venu de l'extérieur (photos, sons,
     * imports), envois d'e-mails à des inconnus, modification du compte
     * partagé, suppressions lourdes (un visiteur ne vide pas la démo des
     * suivants avant la remise à zéro de la nuit).
     */
    'blocked_routes' => [
        // Ajout de photos et vidéos, imports
        'media.store', 'media.uploads.initiate', 'media.uploads.status', 'media.uploads.partUrl',
        'media.uploads.complete', 'media.uploads.abort', 'share-target',
        'google-photos.session', 'google-photos.import', 'google-drive.token', 'google-drive.import',
        'google-drive.connect', 'google-photos.connect', 'takeout.store',
        'family-tree.import.upload', 'family-tree.import.confirm',
        'voice-notes.store',
        // E-mails vers l'extérieur, invitations
        'people.invite', 'households.members.invite',
        // Compte partagé
        'profile.update', 'profile.password', 'account.departure.store', 'account.export',
        // Suppressions lourdes
        'media.destroy', 'albums.destroy', 'people.destroy',
        'households.destroy', 'households.leave', 'households.members.remove',
    ],
];
