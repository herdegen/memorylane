<?php

namespace App\Filament\Resources\MediaResource\Pages;

use App\Filament\Resources\MediaResource;
use Filament\Resources\Pages\ListRecords;

class ListMedia extends ListRecords
{
    protected static string $resource = MediaResource::class;

    // Pas de CreateAction : les médias sont créés par l'upload, pas en admin.
    protected function getHeaderActions(): array
    {
        return [];
    }
}
