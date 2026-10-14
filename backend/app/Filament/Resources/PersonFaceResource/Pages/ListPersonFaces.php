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
        $state = app(\App\Services\Vision\PersonFaceCandidates::class)->counts();
        $note = ! $state['ready'] ? ' Le nombre de suggestions par personne est en cours de calcul (environ une minute).'
            : (! $state['fresh'] ? ' Les nombres entre parenthèses se mettent à jour (environ une minute).' : '');

        return 'Personnes triées par nombre de suggestions du moteur (entre parenthèses) ; leurs visages encore non identifiés, du plus ressemblant au moins ressemblant. Cochez ceux qui sont bien elle et validez en lot.'.$note;
    }
}
