<?php

namespace App\Filament\Resources\PersonResource\RelationManagers;

use App\Models\DetectedFace;
use App\Services\S3Service;
use App\Services\Vision\FaceMatcher;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Onglet « Photos identifiées » d'une personne (admin) : liste les visages qui
 * lui sont associés et permet de les désassocier (une ou plusieurs à la fois)
 * quand la reconnaissance a fait de faux positifs. La désassociation est
 * « collante » via FaceMatcher::disassociate (le visage ne sera pas re-matché
 * automatiquement) et dépollue au passage le jeu de références de la personne.
 */
class IdentifiedPhotosRelationManager extends RelationManager
{
    protected static string $relationship = 'detectedFaces';

    protected static ?string $title = 'Photos identifiées';

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('media.conversions'))
            ->columns([
                ImageColumn::make('thumbnail')
                    ->label('')
                    ->getStateUsing(fn (DetectedFace $record) => static::thumbnailUrl($record))
                    ->height(56)
                    ->square(),
                TextColumn::make('media.original_name')
                    ->label('Fichier')
                    ->limit(40)
                    ->searchable(),
                TextColumn::make('confidence')
                    ->label('Confiance')
                    ->numeric(decimalPlaces: 2)
                    ->alignCenter()
                    ->placeholder('—'),
                TextColumn::make('created_at')
                    ->label('Associé le')
                    ->dateTime('d/m/Y')
                    ->sortable(),
            ])
            ->recordActions([
                Action::make('viewMedia')
                    ->label('Voir')
                    ->icon('heroicon-o-eye')
                    ->color('gray')
                    ->url(fn (DetectedFace $record) => '/media/' . $record->media_id)
                    ->openUrlInNewTab(),
                Action::make('disassociate')
                    ->label('Désassocier')
                    ->icon('heroicon-o-user-minus')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Désassocier cette photo')
                    ->modalDescription('Le visage sera retiré de cette personne et ne sera pas ré-associé automatiquement.')
                    ->action(fn (DetectedFace $record) => static::disassociateFaces(collect([$record]))),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('disassociate')
                        ->label('Désassocier de cette personne')
                        ->icon('heroicon-o-user-minus')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->modalHeading('Désassocier les photos sélectionnées')
                        ->modalDescription('Les visages sélectionnés seront retirés de cette personne et ne seront pas ré-associés automatiquement par la reconnaissance.')
                        ->action(fn (Collection $records) => static::disassociateFaces($records))
                        ->deselectRecordsAfterCompletion(),
                ]),
            ])
            ->emptyStateHeading('Aucune photo identifiée')
            ->emptyStateDescription('Les visages associés à cette personne apparaîtront ici.')
            ->paginated([12, 24, 48, 'all']);
    }

    /**
     * Désassocie une collection de visages de la personne (désassociation
     * collante) et notifie.
     */
    protected static function disassociateFaces(Collection $records): void
    {
        $matcher = app(FaceMatcher::class);

        $records->each(fn (DetectedFace $face) => $matcher->disassociate($face->loadMissing('media')));

        Notification::make()
            ->title($records->count() . ' photo(s) désassociée(s)')
            ->body('Ces visages ne seront pas ré-associés automatiquement.')
            ->success()
            ->send();
    }

    /**
     * URL signée de la plus petite conversion du média (miniature).
     */
    protected static function thumbnailUrl(DetectedFace $record): ?string
    {
        $media = $record->media;

        if (! $media) {
            return null;
        }

        $conversion = $media->conversions
            ->whereIn('conversion_name', ['thumbnail', 'small', 'medium'])
            ->sortBy(fn ($c) => array_search($c->conversion_name, ['thumbnail', 'small', 'medium']))
            ->first();

        if (! $conversion) {
            return null;
        }

        return app(S3Service::class)->getTemporaryUrl($conversion->file_path);
    }
}
