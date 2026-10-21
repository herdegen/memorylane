<?php

namespace App\Filament\Resources\HouseholdResource\Pages;

use App\Filament\Resources\HouseholdResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditHousehold extends EditRecord
{
    protected static string $resource = HouseholdResource::class;

    protected function afterSave(): void
    {
        // Un seul cercle d'accueil à la fois.
        if ($this->getRecord()->is_default) {
            $this->getRecord()->makeWelcome();
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->modalDescription('Le cercle disparaît pour tous ses membres : partages, albums partagés et rattachements de fiches sont retirés. Les photos et fiches elles-mêmes restent.'),
        ];
    }
}
