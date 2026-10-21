<?php

namespace App\Filament\Resources\HouseholdResource\Pages;

use App\Filament\Resources\HouseholdResource;
use Filament\Resources\Pages\CreateRecord;

class CreateHousehold extends CreateRecord
{
    protected static string $resource = HouseholdResource::class;

    /** Le responsable devient membre ; un seul cercle d'accueil. */
    protected function afterCreate(): void
    {
        $circle = $this->getRecord();
        if ($circle->creator) {
            $circle->addMember($circle->creator, auth()->user());
        }
        if ($circle->is_default) {
            $circle->makeWelcome();
        }
    }
}
