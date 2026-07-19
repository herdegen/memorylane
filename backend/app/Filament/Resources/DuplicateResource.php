<?php

namespace App\Filament\Resources;

use App\Filament\Resources\DuplicateResource\Pages\ListDuplicates;
use App\Models\Media;
use App\Services\MediaService;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Revue des doublons EXACTS (issue #42, tranche 1).
 *
 * Regroupe les photos partageant le même `content_hash` (SHA-256) POUR UN MÊME
 * propriétaire — cas typique du même fichier ré-uploadé / réimporté. La
 * détection est quasi gratuite (l'index `['user_id','content_hash']` existe).
 *
 * Ressource « read-only » (index seul, pas de CRUD) sur le modèle Media, sur le
 * modèle de FaceRecognitionResource. Deux niveaux de suppression :
 *  - « corbeille » (soft delete, réversible, les fichiers S3 sont conservés) ;
 *  - « définitive » (force delete + purge S3 original + conversions).
 *
 * Périmètre v1 : photos uniquement (vidéos plus tard). Les quasi-doublons
 * (empreinte perceptuelle / distance de Hamming) feront l'objet des tranches
 * suivantes.
 */
class DuplicateResource extends Resource
{
    protected static ?string $model = Media::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-document-duplicate';

    protected static ?string $navigationLabel = 'Doublons';

    protected static string|\UnitEnum|null $navigationGroup = 'Nettoyage';

    protected static ?string $modelLabel = 'doublon';

    protected static ?string $pluralModelLabel = 'doublons';

    /**
     * Photos faisant partie d'un groupe de doublons exacts : il existe au moins
     * une AUTRE photo non supprimée du même propriétaire avec le même
     * content_hash. On charge le propriétaire, les conversions (miniature +
     * purge S3) et des compteurs d'attaches (albums / personnes / tags) qui
     * servent à désigner la « meilleure » copie à conserver.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('type', 'photo')
            ->whereNotNull('content_hash')
            ->whereExists(function ($q) {
                $q->selectRaw('1')
                    ->from('media as dup')
                    ->whereColumn('dup.content_hash', 'media.content_hash')
                    ->whereColumn('dup.user_id', 'media.user_id')
                    ->where('dup.type', 'photo')
                    ->whereNull('dup.deleted_at')
                    ->whereColumn('dup.id', '!=', 'media.id');
            })
            ->with(['user:id,name', 'conversions:id,media_id,conversion_name,file_path'])
            ->withCount([
                'albums',
                'people',
                'tags',
                'detectedFaces as matched_faces_count' => fn (Builder $q) => $q->where('status', 'matched'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            // Regroupe visuellement les copies identiques ; l'ordre au sein du
            // groupe met la plus ancienne (l'originale probable) en tête.
            ->groups([
                Group::make('content_hash')
                    ->label('Groupe de doublons')
                    ->getTitleFromRecordUsing(fn (Media $record): string => 'Doublon · '.substr((string) $record->content_hash, 0, 10).'…')
                    ->getDescriptionFromRecordUsing(fn (Media $record): ?string => $record->user?->name),
            ])
            ->defaultGroup('content_hash')
            ->defaultSort('uploaded_at', 'asc')
            ->columns([
                ImageColumn::make('thumbnail')
                    ->label('')
                    ->getStateUsing(fn (Media $record) => static::thumbnailUrl($record))
                    ->height(56)
                    ->square(),
                TextColumn::make('original_name')
                    ->label('Fichier')
                    ->limit(30)
                    ->searchable()
                    ->description(fn (Media $record) => $record->user?->name),
                TextColumn::make('height')
                    ->label('Résolution')
                    ->formatStateUsing(fn (Media $record) => $record->resolution_label ?? '—')
                    ->alignCenter(),
                TextColumn::make('size')
                    ->label('Taille')
                    ->formatStateUsing(fn (?int $state) => $state ? static::humanSize($state) : '—')
                    ->alignEnd(),
                TextColumn::make('matched_faces_count')
                    ->label('Visages')
                    ->alignCenter()
                    ->badge()
                    ->color(fn (int $state) => $state > 0 ? 'success' : 'gray'),
                TextColumn::make('albums_count')
                    ->label('Albums')
                    ->alignCenter()
                    ->badge()
                    ->color(fn (int $state) => $state > 0 ? 'info' : 'gray'),
                TextColumn::make('taken_at')
                    ->label('Prise le')
                    ->dateTime('d/m/Y')
                    ->placeholder('—'),
                TextColumn::make('uploaded_at')
                    ->label('Importé le')
                    ->dateTime('d/m/Y H:i'),
            ])
            ->recordActions([
                Action::make('open')
                    ->label('Ouvrir')
                    ->icon('heroicon-o-eye')
                    ->color('gray')
                    ->url(fn (Media $record) => '/media/'.$record->id)
                    ->openUrlInNewTab(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    // Garder la meilleure copie de chaque groupe sélectionné,
                    // mettre le reste à la corbeille (réversible).
                    BulkAction::make('keepBest')
                        ->label('Garder la meilleure, corbeille le reste')
                        ->icon('heroicon-o-sparkles')
                        ->color('primary')
                        ->requiresConfirmation()
                        ->modalHeading('Ne garder que la meilleure de chaque groupe')
                        ->modalDescription('Pour chaque groupe de doublons sélectionné, la meilleure copie est conservée (le plus d\'albums / personnes / tags, puis la plus ancienne) et les autres sont mises à la corbeille.')
                        ->action(function (Collection $records) {
                            $trashed = 0;

                            foreach ($records->groupBy('content_hash') as $group) {
                                if ($group->count() < 2) {
                                    continue;
                                }

                                $keep = static::pickRepresentative($group);

                                foreach ($group as $media) {
                                    if ($media->getKey() !== $keep->getKey()) {
                                        $media->delete();
                                        $trashed++;
                                    }
                                }
                            }

                            static::notifyTrashed($trashed);
                        })
                        ->deselectRecordsAfterCompletion(),
                    // Corbeille (soft delete) de tous les médias sélectionnés.
                    BulkAction::make('trash')
                        ->label('Mettre à la corbeille')
                        ->icon('heroicon-o-trash')
                        ->color('warning')
                        ->requiresConfirmation()
                        ->modalDescription('Les médias sélectionnés sont mis à la corbeille. Les fichiers restent stockés et la suppression est réversible.')
                        ->action(function (Collection $records) {
                            $records->each(fn (Media $media) => $media->delete());
                            static::notifyTrashed($records->count());
                        })
                        ->deselectRecordsAfterCompletion(),
                    // Suppression DÉFINITIVE : purge S3 (original + conversions)
                    // puis force delete (les lignes conversions partent en
                    // cascade DB).
                    BulkAction::make('forceDelete')
                        ->label('Supprimer définitivement')
                        ->icon('heroicon-o-x-circle')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->modalHeading('Suppression définitive')
                        ->modalDescription('Les médias sélectionnés et leurs fichiers (original + miniatures) seront supprimés définitivement. Cette action est irréversible.')
                        ->action(function (Collection $records) {
                            $service = app(MediaService::class);

                            foreach ($records as $media) {
                                $service->purgeStorageFiles($media);
                                $media->forceDelete();
                            }

                            Notification::make()
                                ->title($records->count().' média(s) supprimé(s) définitivement')
                                ->success()
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                ]),
            ]);
    }

    /**
     * Désigne la copie à conserver dans un groupe de doublons : celle qui porte
     * le plus d'attaches (albums, puis personnes, puis tags) pour ne pas perdre
     * d'appartenance, et à égalité la plus ancienne (l'originale probable).
     */
    protected static function pickRepresentative(Collection $group): Media
    {
        return $group
            ->sortBy(fn (Media $m) => $m->uploaded_at?->getTimestamp() ?? PHP_INT_MAX)
            ->sortByDesc(fn (Media $m) => ($m->albums_count * 100) + ($m->people_count * 10) + $m->tags_count)
            ->first();
    }

    protected static function notifyTrashed(int $count): void
    {
        Notification::make()
            ->title($count.' doublon(s) mis à la corbeille')
            ->success()
            ->send();
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

    protected static function humanSize(int $bytes): string
    {
        $units = ['o', 'Ko', 'Mo', 'Go'];
        $i = 0;
        $value = (float) $bytes;

        while ($value >= 1024 && $i < count($units) - 1) {
            $value /= 1024;
            $i++;
        }

        return round($value, $i === 0 ? 0 : 1).' '.$units[$i];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDuplicates::route('/'),
        ];
    }
}
