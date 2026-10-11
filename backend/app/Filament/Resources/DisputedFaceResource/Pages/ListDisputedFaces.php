<?php

namespace App\Filament\Resources\DisputedFaceResource\Pages;

use App\Filament\Resources\DisputedFaceResource;
use Filament\Resources\Pages\ListRecords;

class ListDisputedFaces extends ListRecords
{
    protected static string $resource = DisputedFaceResource::class;

    protected ?string $heading = 'Identifications à vérifier';

    protected static ?string $title = 'Identifications à vérifier';

    public function getSubheading(): ?string
    {
        return 'Identifications existantes qu\'InsightFace remet en question. Les identifications confirmées ici, dans le panneau visage ou par une quête ne réapparaissent plus.';
    }
}
