<?php

namespace App\Filament\Resources;

use App\Filament\Resources\FaceRecognitionResource\Pages\ListFaceRecognition;
use App\Jobs\AnalyzeMediaWithVision;
use App\Models\Media;
use App\Services\Vision\FaceMatcher;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Tableau de bord admin de la reconnaissance de visages (issue #12).
 *
 * Lecture seule + deux relances :
 *  - « Relancer la détection » : remet le média en `pending` (visages effacés)
 *    pour que la détection (navigateur face-api ou job serveur) le reprenne.
 *  - « Relancer l'auto-association » : rejoue le matching serveur sur les
 *    visages non identifiés, scopé au propriétaire du média.
 *
 * La ressource pointe le modèle Media mais n'expose que l'index (pas de CRUD) :
 * la gestion des médias reste dans MediaResource.
 */
class FaceRecognitionResource extends Resource
{
    protected static ?string $model = Media::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-face-smile';

    protected static ?string $navigationLabel = 'Reconnaissance de visages';

    protected static string|\UnitEnum|null $navigationGroup = 'Vision';

    protected static ?string $modelLabel = 'média';

    protected static ?string $pluralModelLabel = 'médias';

    /**
     * Photos uniquement, avec les compteurs de visages agrégés et le
     * propriétaire / les conversions pour la miniature (évite le N+1).
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('type', 'photo')
            ->with(['user:id,name', 'metadata', 'conversions:id,media_id,conversion_name,file_path'])
            ->withCount([
                'detectedFaces',
                'detectedFaces as matched_faces_count' => fn (Builder $q) => $q->where('status', 'matched'),
                'detectedFaces as unmatched_faces_count' => fn (Builder $q) => $q->where('status', 'unmatched'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('uploaded_at', 'desc')
            ->columns([
                ImageColumn::make('thumbnail')
                    ->label('')
                    ->getStateUsing(fn (Media $record) => static::thumbnailUrl($record))
                    ->height(48)
                    ->square(),
                TextColumn::make('original_name')
                    ->label('Fichier')
                    ->limit(30)
                    ->searchable()
                    ->description(fn (Media $record) => $record->user?->name),
                TextColumn::make('metadata.vision_status')
                    ->label('Détection')
                    ->badge()
                    ->default('untouched')
                    ->formatStateUsing(fn (?string $state) => match ($state) {
                        'completed' => 'Traité',
                        'processing' => 'En cours',
                        'pending' => 'En attente',
                        'failed' => 'Échec',
                        default => 'Non traité',
                    })
                    ->color(fn (?string $state) => match ($state) {
                        'completed' => 'success',
                        'processing' => 'info',
                        'pending' => 'warning',
                        'failed' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('metadata.vision_provider')
                    ->label('Source')
                    ->badge()
                    ->color('gray')
                    ->placeholder('—'),
                TextColumn::make('detected_faces_count')
                    ->label('Visages')
                    ->alignCenter()
                    ->badge()
                    ->color('gray'),
                TextColumn::make('matched_faces_count')
                    ->label('Identifiés')
                    ->alignCenter()
                    ->badge()
                    ->color(fn (int $state) => $state > 0 ? 'success' : 'gray'),
                TextColumn::make('unmatched_faces_count')
                    ->label('Non identifiés')
                    ->alignCenter()
                    ->badge()
                    ->color(fn (int $state) => $state > 0 ? 'warning' : 'gray'),
                TextColumn::make('metadata.vision_processed_at')
                    ->label('Traité le')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('vision_status')
                    ->label('État de détection')
                    ->options([
                        'completed' => 'Traité',
                        'pending' => 'En attente',
                        'processing' => 'En cours',
                        'failed' => 'Échec',
                        'untouched' => 'Non traité',
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        $value = $data['value'] ?? null;

                        if (! $value) {
                            return $query;
                        }

                        if ($value === 'untouched') {
                            return $query->where(fn (Builder $q) => $q
                                ->whereDoesntHave('metadata')
                                ->orWhereHas('metadata', fn (Builder $m) => $m->whereNull('vision_status')));
                        }

                        return $query->whereHas('metadata', fn (Builder $q) => $q->where('vision_status', $value));
                    }),
                Filter::make('has_unidentified')
                    ->label('Avec visages non identifiés')
                    ->query(fn (Builder $query) => $query->whereHas('detectedFaces', fn (Builder $q) => $q->where('status', 'unmatched'))),
            ])
            ->recordActions([
                Action::make('reanalyzeDetection')
                    ->label('Relancer la détection')
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalHeading('Relancer la détection des visages')
                    ->modalDescription('Les visages actuels seront effacés et le média repassé « en attente » pour une nouvelle détection.')
                    ->action(fn (Media $record) => static::resetDetection($record)),
                Action::make('autoMatch')
                    ->label('Relancer l\'auto-association')
                    ->icon('heroicon-o-user-plus')
                    ->color('primary')
                    ->action(fn (Media $record) => static::runAutoMatch($record)),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('reanalyzeDetection')
                        ->label('Relancer la détection')
                        ->icon('heroicon-o-arrow-path')
                        ->color('warning')
                        ->requiresConfirmation()
                        ->action(function (Collection $records) {
                            $records->each(fn (Media $record) => static::resetDetection($record, notify: false));

                            Notification::make()
                                ->title($records->count().' média(s) remis en file de détection')
                                ->success()
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                    BulkAction::make('autoMatch')
                        ->label('Relancer l\'auto-association')
                        ->icon('heroicon-o-user-plus')
                        ->color('primary')
                        ->action(function (Collection $records) {
                            $matched = $records->sum(fn (Media $record) => static::runAutoMatch($record, notify: false));

                            Notification::make()
                                ->title($matched.' visage(s) associé(s) automatiquement')
                                ->success()
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                ]),
            ]);
    }

    /**
     * Efface les visages, remet le média « en attente » et redispatche le job
     * serveur (no-op si aucun service Vision n'est configuré : le scan
     * navigateur reprendra le média via /vision/pending). Miroir de
     * VisionController::reanalyze mais utilisable côté admin.
     */
    protected static function resetDetection(Media $record, bool $notify = true): void
    {
        $record->detectedFaces()->delete();

        $record->metadata()->updateOrCreate([], [
            'vision_status' => 'pending',
            'vision_labels' => null,
            'vision_error' => null,
            'vision_faces_count' => 0,
            'vision_processed_at' => null,
        ]);

        AnalyzeMediaWithVision::dispatch($record);

        if ($notify) {
            Notification::make()
                ->title('Détection relancée')
                ->body('Le média a été remis en file de traitement.')
                ->success()
                ->send();
        }
    }

    /**
     * Rejoue l'auto-association serveur sur les visages non identifiés du média,
     * scopée à son propriétaire. Retourne le nombre de visages nouvellement
     * associés.
     */
    protected static function runAutoMatch(Media $record, bool $notify = true): int
    {
        $matcher = app(FaceMatcher::class);

        $faces = $record->detectedFaces()
            ->where('status', 'unmatched')
            ->whereNotNull('embedding')
            ->get();

        $matched = 0;

        foreach ($faces as $face) {
            $face->setRelation('media', $record);

            if ($matcher->autoMatch($face, $record->user_id)) {
                $matched++;
            }
        }

        if ($notify) {
            Notification::make()
                ->title($matched > 0 ? $matched.' visage(s) associé(s)' : 'Aucune association trouvée')
                ->{$matched > 0 ? 'success' : 'warning'}()
                ->send();
        }

        return $matched;
    }

    /**
     * URL signée de la plus petite conversion disponible pour la miniature.
     */
    protected static function thumbnailUrl(Media $record): ?string
    {
        $conversion = $record->conversions
            ->whereIn('conversion_name', ['thumbnail', 'small', 'medium'])
            ->sortBy(fn ($c) => array_search($c->conversion_name, ['thumbnail', 'small', 'medium']))
            ->first();

        if (! $conversion) {
            return null;
        }

        return app(\App\Services\S3Service::class)->getTemporaryUrl($conversion->file_path);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListFaceRecognition::route('/'),
        ];
    }
}
