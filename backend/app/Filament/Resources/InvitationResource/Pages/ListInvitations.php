<?php

namespace App\Filament\Resources\InvitationResource\Pages;

use App\Filament\Resources\InvitationResource;
use App\Models\Household;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListInvitations extends ListRecords
{
    protected static string $resource = InvitationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Foyer rejoint automatiquement par les invités (y compris ceux
            // qu'invitent les membres).
            Action::make('welcomeHousehold')
                ->label(fn () => 'Foyer d\'accueil : '.(Household::welcome()?->name ?? 'aucun'))
                ->icon('heroicon-o-home')
                ->color('gray')
                ->modalHeading('Foyer d\'accueil')
                ->modalDescription('Toute personne invitée rejoint ce foyer et voit les photos qui y sont partagées.')
                ->schema([
                    Select::make('household_id')
                        ->label('Foyer')
                        ->options(fn () => Household::orderBy('name')->pluck('name', 'id'))
                        ->default(fn () => Household::welcome()?->id)
                        ->required(),
                ])
                ->action(function (array $data) {
                    Household::findOrFail($data['household_id'])->makeWelcome();
                    Notification::make()->title('Foyer d\'accueil mis à jour')->success()->send();
                }),
            CreateAction::make()->label('Inviter quelqu\'un'),
        ];
    }
}
