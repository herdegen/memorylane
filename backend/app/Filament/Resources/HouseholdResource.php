<?php

namespace App\Filament\Resources;

use App\Filament\Support\UnaccentSearch;
use App\Filament\Resources\HouseholdResource\Pages\CreateHousehold;
use App\Filament\Resources\HouseholdResource\Pages\EditHousehold;
use App\Filament\Resources\HouseholdResource\Pages\ListHouseholds;
use App\Filament\Resources\HouseholdResource\RelationManagers\MembersRelationManager;
use App\Filament\Resources\HouseholdResource\RelationManagers\PeopleRelationManager;
use App\Models\Household;
use App\Models\Person;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * Cercles (famille / amis) : vue d'ensemble de tous les groupes, réglages,
 * membres (comptes) et fiches rattachées (cercles d'amis).
 */
class HouseholdResource extends Resource
{
    protected static ?string $model = Household::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-circle-stack';

    protected static ?string $navigationLabel = 'Cercles';

    protected static ?string $modelLabel = 'Cercle';

    protected static ?string $pluralModelLabel = 'Cercles';

    public const KINDS = [Household::KIND_FAMILY => 'Famille', Household::KIND_FRIENDS => 'Amis'];

    public const POLICIES = [
        Household::INVITE_EVERYONE => 'Tous les membres peuvent ajouter des gens',
        Household::INVITE_ADMIN => 'Seul le responsable (ou un admin) ajoute des gens',
    ];

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->label('Nom')
                ->required()
                ->maxLength(255),
            Select::make('kind')
                ->label('Type')
                ->options(self::KINDS)
                ->required()
                ->default(Household::KIND_FRIENDS)
                ->live()
                ->afterStateUpdated(fn ($state, $set) => $set('invite_policy', Household::defaultInvitePolicy($state)))
                // Changer le type d'un cercle existant bouleverse qui voit quoi : création seulement.
                ->disabledOn('edit')
                ->helperText('Famille : arbre et répertoire communs. Amis : seules les fiches rattachées sont visibles des membres.'),
            Select::make('invite_policy')
                ->label('Qui peut ajouter des membres')
                ->options(self::POLICIES)
                ->required()
                ->default(Household::INVITE_ADMIN),
            Select::make('created_by')
                ->label('Responsable')
                ->relationship('creator', 'name')
                ->required()
                ->searchable()
                ->getSearchResultsUsing(UnaccentSearch::options(fn () => \App\Models\User::query(), ['name']))
                ->default(fn () => auth()->id()),
            Toggle::make('is_default')
                ->label('Cercle d\'accueil')
                ->helperText('Rejoint automatiquement par toute personne invitée. Un seul à la fois.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('creator')->withCount(['members', 'people']))
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')
                    ->label('Nom')
                    ->searchable(query: UnaccentSearch::columns(['name']))
                    ->sortable(),
                TextColumn::make('kind')
                    ->label('Type')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => self::KINDS[$state] ?? $state)
                    ->color(fn (string $state) => $state === Household::KIND_FAMILY ? 'warning' : 'info'),
                TextColumn::make('members_count')
                    ->label('Membres')
                    ->sortable(),
                TextColumn::make('fiches')
                    ->label('Fiches')
                    ->getStateUsing(fn (Household $record) => $record->isFriends()
                        ? $record->people_count
                        : Person::where('in_family_directory', true)->count()),
                TextColumn::make('invite_policy')
                    ->label('Ajouts')
                    ->formatStateUsing(fn (string $state) => $state === Household::INVITE_EVERYONE ? 'Tous' : 'Responsable'),
                IconColumn::make('is_default')
                    ->label('Accueil')
                    ->boolean(),
                TextColumn::make('creator.name')
                    ->label('Responsable')
                    ->placeholder('—'),
                TextColumn::make('created_at')
                    ->label('Créé le')
                    ->dateTime('d/m/Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('kind')->label('Type')->options(self::KINDS),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            PeopleRelationManager::class,
            MembersRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListHouseholds::route('/'),
            'create' => CreateHousehold::route('/create'),
            'edit' => EditHousehold::route('/{record}/edit'),
        ];
    }
}
