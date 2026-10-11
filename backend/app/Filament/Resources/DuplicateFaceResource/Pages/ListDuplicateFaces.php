<?php

namespace App\Filament\Resources\DuplicateFaceResource\Pages;

use App\Filament\Resources\DuplicateFaceResource;
use App\Services\Vision\DuplicateFaceResolver;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListDuplicateFaces extends ListRecords
{
    protected static string $resource = DuplicateFaceResource::class;

    protected ?string $heading = 'Visages en double';

    protected static ?string $title = 'Visages en double';

    protected function getHeaderActions(): array
    {
        $resolver = app(DuplicateFaceResolver::class);
        $clear = collect($resolver->analysis())->where('suggested', true)->where('clear', true)->count();

        return [
            Action::make('applyClear')
                ->label("Appliquer les suggestions nettes ({$clear})")
                ->icon('heroicon-o-sparkles')
                ->disabled($clear === 0)
                ->requiresConfirmation()
                ->modalHeading('Appliquer les suggestions nettes')
                ->modalDescription("Pour les {$clear} groupe(s) où un visage ressemble nettement plus à la personne que les autres, il est gardé ; les autres visages redeviennent « à identifier ». Les groupes serrés restent à trancher à la main.")
                ->action(function () use ($resolver) {
                    $r = $resolver->applyClearSuggestions();
                    Notification::make()
                        ->title("{$r['groups']} groupe(s) résolu(s), {$r['faces']} visage(s) à identifier de nouveau")
                        ->success()->send();
                }),
        ];
    }
}
