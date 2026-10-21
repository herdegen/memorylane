<?php

namespace App\Filament\Resources\HouseholdResource\RelationManagers;

use App\Filament\Support\UnaccentSearch;
use App\Models\Household;
use Filament\Actions\AttachAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DetachAction;
use Filament\Actions\DetachBulkAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Fiches rattachées à un cercle d'amis (visibles de ses membres). La famille
 * n'a pas de rattachement : c'est la case « Famille » de chaque fiche.
 */
class PeopleRelationManager extends RelationManager
{
    protected static string $relationship = 'people';

    protected static ?string $inverseRelationship = 'circles';

    protected static ?string $title = 'Fiches';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord instanceof Household && $ownerRecord->isFriends();
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')->label('Nom')->searchable(query: UnaccentSearch::columns(['first_name', 'last_name', 'name', 'maiden_name']))->sortable(),
                TextColumn::make('in_family_directory')
                    ->label('Aussi dans la famille')
                    ->formatStateUsing(fn ($state) => $state ? 'Oui' : '—'),
                TextColumn::make('pivot.created_at')->label('Ajoutée le')->dateTime('d/m/Y'),
            ])
            ->headerActions([
                AttachAction::make()
                    ->label('Ajouter une fiche')
                    ->preloadRecordSelect()
                    ->recordSelect(fn (\Filament\Forms\Components\Select $select) => $select->getSearchResultsUsing(
                        UnaccentSearch::options(fn () => \App\Models\Person::whereDoesntHave('circles', fn ($c) => $c->whereKey($this->getOwnerRecord()->getKey())), ['first_name', 'last_name', 'name', 'maiden_name'])
                    ))
                    ->multiple()
                    ->mutateDataUsing(fn (array $data) => $data + ['added_by' => auth()->id()]),
            ])
            ->recordActions([
                DetachAction::make()->label('Retirer du cercle'),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DetachBulkAction::make()->label('Retirer du cercle'),
                ]),
            ]);
    }
}
