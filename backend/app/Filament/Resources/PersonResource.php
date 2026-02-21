<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PersonResource\Pages;
use App\Models\Person;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class PersonResource extends Resource
{
    protected static ?string $model = Person::class;

    protected static ?string $navigationIcon = 'heroicon-o-user-group';

    protected static ?string $navigationLabel = 'Personnes';

    protected static ?string $modelLabel = 'Personne';

    protected static ?string $pluralModelLabel = 'Personnes';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('user_id')
                    ->relationship('user', 'name')
                    ->required()
                    ->searchable()
                    ->preload()
                    ->label('Propriétaire'),
                Forms\Components\TextInput::make('name')
                    ->required()
                    ->maxLength(255)
                    ->label('Nom'),
                Forms\Components\TextInput::make('maiden_name')
                    ->maxLength(255)
                    ->label('Nom de naissance'),
                Forms\Components\Select::make('gender')
                    ->options([
                        'M' => 'Masculin',
                        'F' => 'Féminin',
                        'U' => 'Non spécifié',
                    ])
                    ->default('U')
                    ->label('Genre'),
                Forms\Components\DatePicker::make('birth_date')
                    ->label('Date de naissance'),
                Forms\Components\TextInput::make('birth_place')
                    ->maxLength(255)
                    ->label('Lieu de naissance'),
                Forms\Components\DatePicker::make('death_date')
                    ->label('Date de décès'),
                Forms\Components\TextInput::make('death_place')
                    ->maxLength(255)
                    ->label('Lieu de décès'),
                Forms\Components\Select::make('father_id')
                    ->relationship('father', 'name')
                    ->searchable()
                    ->preload()
                    ->label('Père'),
                Forms\Components\Select::make('mother_id')
                    ->relationship('mother', 'name')
                    ->searchable()
                    ->preload()
                    ->label('Mère'),
                Forms\Components\Textarea::make('notes')
                    ->maxLength(2000)
                    ->label('Notes'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Nom')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('gender')
                    ->label('Genre')
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'M' => '♂ Masculin',
                        'F' => '♀ Féminin',
                        default => 'Non spécifié',
                    })
                    ->sortable(),
                Tables\Columns\TextColumn::make('user.name')
                    ->label('Propriétaire')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('birth_date')
                    ->label('Naissance')
                    ->date('d/m/Y')
                    ->sortable(),
                Tables\Columns\TextColumn::make('death_date')
                    ->label('Décès')
                    ->date('d/m/Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('father.name')
                    ->label('Père')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('mother.name')
                    ->label('Mère')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('media_count')
                    ->label('Médias')
                    ->counts('media')
                    ->sortable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Créé le')
                    ->dateTime('d/m/Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\TrashedFilter::make(),
                Tables\Filters\SelectFilter::make('user')
                    ->relationship('user', 'name')
                    ->label('Propriétaire'),
                Tables\Filters\SelectFilter::make('gender')
                    ->options([
                        'M' => 'Masculin',
                        'F' => 'Féminin',
                        'U' => 'Non spécifié',
                    ])
                    ->label('Genre'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                    Tables\Actions\ForceDeleteBulkAction::make(),
                    Tables\Actions\RestoreBulkAction::make(),
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
            'index' => Pages\ListPeople::route('/'),
            'create' => Pages\CreatePerson::route('/create'),
            'edit' => Pages\EditPerson::route('/{record}/edit'),
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
