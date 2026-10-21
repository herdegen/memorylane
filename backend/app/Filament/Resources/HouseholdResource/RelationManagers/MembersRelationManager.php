<?php

namespace App\Filament\Resources\HouseholdResource\RelationManagers;

use App\Models\Household;
use App\Models\User;
use App\Services\DepartureService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Validation\ValidationException;

/**
 * Comptes membres du cercle. Ajout = même effet que l'invitation de l'app
 * (la fiche du compte suit dans un cercle d'amis) ; retrait = même départ
 * que dans l'app (DepartureService), photos gardées ou retirées au choix.
 */
class MembersRelationManager extends RelationManager
{
    protected static string $relationship = 'members';

    protected static ?string $title = 'Membres';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')->label('Nom')->searchable()->sortable(),
                TextColumn::make('email')->label('Email')->searchable(),
                TextColumn::make('role')
                    ->label('Rôle')
                    ->getStateUsing(fn (User $record) => $record->id === $this->getOwnerRecord()->created_by ? 'Responsable' : null)
                    ->badge()
                    ->placeholder('—'),
                TextColumn::make('pivot.created_at')->label('Membre depuis')->dateTime('d/m/Y'),
            ])
            ->headerActions([
                Action::make('addMember')
                    ->label('Ajouter un membre')
                    ->schema([
                        Select::make('user_id')
                            ->label('Compte')
                            ->options(fn () => User::whereNotIn('id', $this->getOwnerRecord()->members()->pluck('users.id'))
                                ->orderBy('name')->pluck('name', 'id'))
                            ->searchable()
                            ->required(),
                    ])
                    ->action(function (array $data) {
                        /** @var Household $circle */
                        $circle = $this->getOwnerRecord();
                        $circle->addMember(User::findOrFail($data['user_id']), auth()->user());
                        Notification::make()->title('Membre ajouté')->success()->send();
                    }),
            ])
            ->recordActions([
                Action::make('remove')
                    ->label('Retirer')
                    ->color('danger')
                    ->icon('heroicon-o-user-minus')
                    ->requiresConfirmation()
                    ->modalDescription('Comme un départ dans l\'app. Pour un responsable de groupe, choisissez qui reprend.')
                    ->schema(fn (User $record) => array_filter([
                        Select::make('photos')
                            ->label('Ses photos et albums partagés avec le cercle')
                            ->options(['keep' => 'Restent dans le cercle', 'withdraw' => 'En sont retirés'])
                            ->default('keep')
                            ->required(),
                        $this->needsSuccessor($record)
                            ? Select::make('successor_id')
                                ->label('Nouveau responsable')
                                ->options(fn () => $this->getOwnerRecord()->members()->where('users.id', '!=', $record->id)->orderBy('name')->pluck('name', 'users.id'))
                                ->required()
                            : null,
                    ]))
                    ->action(function (User $record, array $data) {
                        // Dans l'app, le départ du dernier membre ferme le groupe : ici on
                        // ne supprime jamais un cercle par ricochet.
                        if (app(DepartureService::class)->circleSummary($record, $this->getOwnerRecord())['last_member']) {
                            Notification::make()
                                ->title('C\'est le dernier membre')
                                ->body('Le retirer fermerait le cercle. Supprimez le cercle si c\'est voulu.')
                                ->warning()
                                ->send();

                            return;
                        }
                        try {
                            app(DepartureService::class)->leaveCircle($record, $this->getOwnerRecord(), $data['photos'], $data['successor_id'] ?? null);
                            Notification::make()->title("{$record->name} a été retiré(e) du cercle")->success()->send();
                        } catch (ValidationException $e) {
                            Notification::make()->title(collect($e->errors())->flatten()->first())->danger()->send();
                        }
                    }),
            ]);
    }

    private function needsSuccessor(User $user): bool
    {
        return app(DepartureService::class)->circleSummary($user, $this->getOwnerRecord())['needs_successor'];
    }
}
