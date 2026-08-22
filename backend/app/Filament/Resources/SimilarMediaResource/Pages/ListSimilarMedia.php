<?php

namespace App\Filament\Resources\SimilarMediaResource\Pages;

use App\Filament\Resources\SimilarMediaResource;
use App\Services\SimilarMediaClusterer;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListSimilarMedia extends ListRecords
{
    protected static string $resource = SimilarMediaResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // (Re)lance le clustering perceptuel avec un seuil réglable.
            // Synchrone : ~700 photos se comparent en quelques dizaines de ms.
            Action::make('recluster')
                ->label('Recalculer les groupes')
                ->icon('heroicon-o-arrow-path')
                ->schema([
                    TextInput::make('threshold')
                        ->label('Seuil (distance de Hamming, 0-64)')
                        ->helperText('Plus bas = plus strict. 0-2 : quasi identiques ; 8 (défaut) : rafales et recompressions ; au-delà de 12, risque de faux positifs.')
                        ->numeric()
                        ->minValue(0)
                        ->maxValue(64)
                        ->default(SimilarMediaClusterer::DEFAULT_THRESHOLD)
                        ->required(),
                ])
                ->action(function (array $data) {
                    $result = app(SimilarMediaClusterer::class)->cluster((int) $data['threshold']);

                    Notification::make()
                        ->title("{$result['groups']} groupe(s) — {$result['grouped']} photo(s) groupée(s) sur {$result['photos']} analysée(s)")
                        ->success()
                        ->send();
                }),
        ];
    }
}
