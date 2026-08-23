<?php

namespace App\Filament\Resources\PersonResource\Pages;

use App\Filament\Resources\PersonResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;

class EditPerson extends EditRecord
{
    protected static string $resource = PersonResource::class;

    /**
     * Filament remplit le formulaire via attributesToArray(), qui exclut les
     * champs $hidden : réinjecter l'adresse pour que le champ soit pré-rempli.
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['address'] = $this->getRecord()->address;

        return $data;
    }

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
            ForceDeleteAction::make(),
            RestoreAction::make(),
        ];
    }
}
