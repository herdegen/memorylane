<?php

namespace App\Filament\Resources;

use App\Filament\Resources\MediaResource\Pages\EditMedia;
use App\Filament\Resources\MediaResource\Pages\ListMedia;
use App\Models\Media;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class MediaResource extends Resource
{
    protected static ?string $model = Media::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-rectangle-stack';

    /**
     * Seules les métadonnées « métier » sont éditables. Les champs techniques
     * (propriétaire, chemin S3, MIME, dimensions…) sont dérivés du fichier par
     * les jobs d'import : ils sont affichés en lecture seule et jamais
     * renvoyés à la sauvegarde (dehydrated(false)).
     */
    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Métadonnées')
                    ->columns(2)
                    ->components([
                        TextInput::make('original_name')
                            ->label('Nom d\'origine')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('title')
                            ->label('Titre')
                            ->maxLength(255),
                        Textarea::make('description')
                            ->label('Description')
                            ->maxLength(2000)
                            ->columnSpanFull(),
                        DateTimePicker::make('taken_at')
                            ->label('Pris le'),
                    ]),
                Section::make('Données techniques (lecture seule)')
                    ->columns(2)
                    ->collapsed()
                    ->components([
                        TextInput::make('user_id')
                            ->label('Propriétaire')
                            ->formatStateUsing(fn (?Media $record) => $record?->user?->name ?? $record?->user_id)
                            ->disabled()
                            ->dehydrated(false),
                        TextInput::make('type')
                            ->disabled()
                            ->dehydrated(false),
                        TextInput::make('mime_type')
                            ->disabled()
                            ->dehydrated(false),
                        TextInput::make('size')
                            ->label('Taille (octets)')
                            ->disabled()
                            ->dehydrated(false),
                        TextInput::make('width')
                            ->disabled()
                            ->dehydrated(false),
                        TextInput::make('height')
                            ->disabled()
                            ->dehydrated(false),
                        TextInput::make('duration')
                            ->label('Durée (s)')
                            ->disabled()
                            ->dehydrated(false),
                        DateTimePicker::make('uploaded_at')
                            ->label('Téléversé le')
                            ->disabled()
                            ->dehydrated(false),
                        TextInput::make('file_path')
                            ->label('Chemin S3')
                            ->disabled()
                            ->dehydrated(false)
                            ->columnSpanFull(),
                        TextInput::make('content_hash')
                            ->label('Empreinte (sha256)')
                            ->disabled()
                            ->dehydrated(false)
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('original_name')
                    ->label('Nom d\'origine')
                    ->searchable(),
                TextColumn::make('user.name')
                    ->label('Propriétaire')
                    ->sortable(),
                TextColumn::make('type')
                    ->searchable(),
                TextColumn::make('mime_type')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('size')
                    ->label('Taille')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('file_path')
                    ->label('Chemin S3')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('uploaded_at')
                    ->label('Téléversé le')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('taken_at')
                    ->label('Pris le')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('deleted_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('uploaded_at', 'desc')
            ->filters([
                TrashedFilter::make(),
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
        return [
            //
        ];
    }

    /**
     * Pas de page de création : un Media naît uniquement d'un upload (fichier
     * S3 + jobs de conversion) — un enregistrement créé à la main serait
     * orphelin de son fichier.
     */
    public static function getPages(): array
    {
        return [
            'index' => ListMedia::route('/'),
            'edit' => EditMedia::route('/{record}/edit'),
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
