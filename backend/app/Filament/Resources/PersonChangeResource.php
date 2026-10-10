<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PersonChangeResource\Pages\ListPersonChanges;
use App\Models\PersonChange;
use App\Services\PersonChangeReverter;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Historique de l'arbre : qui a modifié quelle fiche, avec annulation.
 */
class PersonChangeResource extends Resource
{
    protected static ?string $model = PersonChange::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-clock';

    protected static ?string $navigationLabel = 'Historique de l\'arbre';

    protected static ?string $modelLabel = 'Modification';

    protected static ?string $pluralModelLabel = 'Historique de l\'arbre';

    protected static ?int $navigationSort = 2;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['person', 'user', 'revertedBy']))
            ->columns([
                TextColumn::make('created_at')
                    ->label('Quand')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
                TextColumn::make('user.name')
                    ->label('Par')
                    ->placeholder('—')
                    ->searchable(),
                TextColumn::make('person.name')
                    ->label('Fiche')
                    ->searchable()
                    ->url(fn (PersonChange $record) => '/people/'.$record->person_id)
                    ->openUrlInNewTab(),
                TextColumn::make('action')
                    ->label('Action')
                    ->formatStateUsing(fn (string $state) => PersonChange::ACTION_LABELS[$state] ?? $state)
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        'deleted', 'spouse_removed' => 'danger',
                        'created', 'spouse_added', 'restored' => 'success',
                        default => 'gray',
                    }),
                TextColumn::make('summary')
                    ->label('Détail')
                    ->state(fn (PersonChange $record) => $record->summary())
                    ->wrap(),
                TextColumn::make('reverted_at')
                    ->label('Annulée')
                    ->dateTime('d/m/Y H:i')
                    ->description(fn (PersonChange $record) => $record->revertedBy?->name)
                    ->placeholder('—')
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('user_id')
                    ->label('Par')
                    ->relationship('user', 'name'),
                SelectFilter::make('action')
                    ->label('Action')
                    ->options(PersonChange::ACTION_LABELS),
                TernaryFilter::make('reverted')
                    ->label('Annulées')
                    ->queries(
                        true: fn (Builder $q) => $q->whereNotNull('reverted_at'),
                        false: fn (Builder $q) => $q->whereNull('reverted_at'),
                    ),
            ])
            ->recordActions([
                Action::make('revert')
                    ->label('Annuler')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('warning')
                    ->visible(fn (PersonChange $record) => $record->isRevertible())
                    ->requiresConfirmation()
                    ->modalHeading('Annuler cette modification ?')
                    ->modalDescription(fn (PersonChange $record) => match ($record->action) {
                        'updated' => 'Les champs reprendront leur ancienne valeur : '.$record->summary(),
                        'created' => 'La fiche sera supprimée (récupérable dans la corbeille des personnes).',
                        'deleted' => 'La fiche sera restaurée.',
                        default => $record->summary(),
                    })
                    ->action(function (PersonChange $record) {
                        app(PersonChangeReverter::class)->revert($record, auth()->user());
                        Notification::make()->title('Modification annulée')->success()->send();
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPersonChanges::route('/'),
        ];
    }
}
