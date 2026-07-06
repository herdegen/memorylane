<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PersonResource\Pages\CreatePerson;
use App\Filament\Resources\PersonResource\Pages\EditPerson;
use App\Filament\Resources\PersonResource\Pages\ListPeople;
use App\Models\Person;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class PersonResource extends Resource
{
    protected static ?string $model = Person::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-user-group';

    protected static ?string $navigationLabel = 'Personnes';

    protected static ?string $modelLabel = 'Personne';

    protected static ?string $pluralModelLabel = 'Personnes';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('user_id')
                    ->relationship('user', 'name')
                    ->required()
                    ->searchable()
                    ->preload()
                    ->label('Propriétaire'),
                TextInput::make('first_name')
                    ->required()
                    ->maxLength(255)
                    ->label('Prénom(s)'),
                TextInput::make('last_name')
                    ->maxLength(255)
                    ->label('Nom de famille'),
                TextInput::make('maiden_name')
                    ->maxLength(255)
                    ->label('Nom de naissance'),
                Select::make('gender')
                    ->options([
                        'M' => 'Masculin',
                        'F' => 'Féminin',
                        'U' => 'Non spécifié',
                    ])
                    ->default('U')
                    ->label('Genre'),
                DatePicker::make('birth_date')
                    ->label('Date de naissance'),
                TextInput::make('birth_place')
                    ->maxLength(255)
                    ->label('Lieu de naissance'),
                DatePicker::make('death_date')
                    ->label('Date de décès'),
                TextInput::make('death_place')
                    ->maxLength(255)
                    ->label('Lieu de décès'),
                Select::make('father_id')
                    ->relationship('father', 'name')
                    ->searchable()
                    ->preload()
                    ->label('Père'),
                Select::make('mother_id')
                    ->relationship('mother', 'name')
                    ->searchable()
                    ->preload()
                    ->label('Mère'),
                Textarea::make('notes')
                    ->maxLength(2000)
                    ->label('Notes'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Nom')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('gender')
                    ->label('Genre')
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'M' => '♂ Masculin',
                        'F' => '♀ Féminin',
                        default => 'Non spécifié',
                    })
                    ->sortable(),
                TextColumn::make('user.name')
                    ->label('Propriétaire')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('birth_date')
                    ->label('Naissance')
                    ->date('d/m/Y')
                    ->sortable(),
                TextColumn::make('death_date')
                    ->label('Décès')
                    ->date('d/m/Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('father.name')
                    ->label('Père')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('mother.name')
                    ->label('Mère')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('media_count')
                    ->label('Médias')
                    ->counts('media')
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label('Créé le')
                    ->dateTime('d/m/Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TrashedFilter::make(),
                SelectFilter::make('user')
                    ->relationship('user', 'name')
                    ->label('Propriétaire'),
                SelectFilter::make('gender')
                    ->options([
                        'M' => 'Masculin',
                        'F' => 'Féminin',
                        'U' => 'Non spécifié',
                    ])
                    ->label('Genre'),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    ForceDeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPeople::route('/'),
            'create' => CreatePerson::route('/create'),
            'edit' => EditPerson::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }
}
