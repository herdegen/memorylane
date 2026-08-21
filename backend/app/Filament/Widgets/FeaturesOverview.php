<?php

namespace App\Filament\Widgets;

use Filament\Widgets\Widget;

/**
 * Panorama des fonctionnalités de l'instance — anciennement affiché sur
 * l'accueil utilisateur, déplacé ici : il parle aux administrateurs qui
 * installent/configurent, pas à la famille au quotidien.
 */
class FeaturesOverview extends Widget
{
    protected string $view = 'filament.widgets.features-overview';

    protected int|string|array $columnSpan = 'full';

    protected static ?int $sort = 10;

    public function getFeatures(): array
    {
        return [
            ['name' => 'Galerie Photos & Vidéos', 'description' => 'Upload multipart S3, organisation et visualisation des médias', 'available' => true],
            ['name' => 'Système de Tags', 'description' => 'Tags personnalisés globaux, attachés aux médias', 'available' => true],
            ['name' => 'Géolocalisation', 'description' => 'Carte interactive, géolocalisation unitaire ou de masse', 'available' => true],
            ['name' => 'Extraction EXIF', 'description' => 'Métadonnées extraites automatiquement à l\'import', 'available' => true],
            ['name' => 'Albums & Partage', 'description' => 'Albums (dont intelligents), partage par compte ou lien', 'available' => true],
            ['name' => 'Foyers', 'description' => 'Cercles familiaux : partage de médias et identification collaborative', 'available' => true],
            ['name' => 'Arbre Généalogique', 'description' => 'Visualisation family-chart et import GEDCOM', 'available' => true],
            ['name' => 'Reconnaissance de visages', 'description' => 'Détection et suggestion côté navigateur (face-api.js)', 'available' => true],
        ];
    }
}
