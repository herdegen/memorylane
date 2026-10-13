<?php

namespace App\Filament\Resources\PersonFaceResource\Pages;

use App\Filament\Resources\PersonFaceResource;
use Filament\Resources\Pages\ListRecords;

class ListPersonFaces extends ListRecords
{
    protected static string $resource = PersonFaceResource::class;

    protected ?string $heading = 'Identifier par personne';

    protected static ?string $title = 'Identifier par personne';

    public function getSubheading(): ?string
    {
        return 'Visages encore non identifiés qui ressemblent à la personne choisie, du plus ressemblant au moins ressemblant. Cochez ceux qui sont bien elle et validez en lot.';
    }
}
