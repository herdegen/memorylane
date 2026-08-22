<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SimilarMediaResource\Pages\ListSimilarMedia;
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
 * Revue des QUASI-doublons (issue #42, tranche 3).
 *
 * Regroupe les photos visuellement proches (rafales, recompressions, légers
 * recadrages) d'un même propriétaire, détectées par distance de Hamming entre
 * empreintes dHash puis matérialisées dans media.similar_group_id par
 * SimilarMediaClusterer (bouton « Recalculer » de la page, ou commande
 * media:cluster-similar). Les doublons binaires exacts relèvent de l'écran
 * « Doublons » (tranche 1).
 *
 * Même modèle que DuplicateResource (read-only, corbeille / définitive /
 * garder la meilleure), avec une heuristique « meilleure photo » affinée pour
 * les rafales : attaches d'abord, puis résolution, puis poids, puis ancienneté.
 */
class SimilarMediaResource extends Resource
{
    protected static ?string $model = Media::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-square-2-stack';

    protected static ?string $navigationLabel = 'Quasi-doublons';

    protected static string|\UnitEnum|null $navigationGroup = 'Nettoyage';

    protected static ?string $modelLabel = 'quasi-doublon';

    protected static ?string $pluralModelLabel = 'quasi-doublons';

    /**
     * Photos appartenant à un groupe de similarité qui compte encore au moins
     * une AUTRE photo vivante : un groupe dont on a nettoyé tous les jumeaux
     * disparaît de l'écran sans attendre un recalcul.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('type', 'photo')
            ->whereNotNull('similar_group_id')
            ->whereExists(function ($q) {
                $q->selectRaw('1')
                    ->from('media as sib')
                    ->whereColumn('sib.similar_group_id', 'media.similar_group_id')
                    ->where('sib.type', 'photo')
                    ->whereNull('sib.deleted_at')
                    ->whereColumn('sib.id', '!=', 'media.id');
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
            ->groups([
                Group::make('similar_group_id')
                    ->label('Groupe de photos similaires')
                    ->getTitleFromRecordUsing(fn (Media $record): string => 'Similaires · '.substr((string) $record->similar_group_id, 0, 8).'…')
                    ->getDescriptionFromRecordUsing(fn (Media $record): ?string => $record->user?->name),
            ])
            ->defaultGroup('similar_group_id')
            ->defaultSort('taken_at', 'asc')
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
                    ->dateTime('d/m/Y H:i')
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
                    BulkAction::make('keepBest')
                        ->label('Garder la meilleure, corbeille le reste')
                        ->icon('heroicon-o-sparkles')
                        ->color('primary')
                        ->requiresConfirmation()
                        ->modalHeading('Ne garder que la meilleure de chaque groupe')
                        ->modalDescription('Pour chaque groupe sélectionné, la meilleure photo est conservée (attaches album/personne/tag, puis résolution, puis poids, puis la plus ancienne) et les autres sont mises à la corbeille.')
                        ->action(function (Collection $records) {
                            $trashed = 0;

                            foreach ($records->groupBy('similar_group_id') as $group) {
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
                    BulkAction::make('forceDelete')
                        ->label('Supprimer définitivement')
                        ->icon('heroicon-o-x-circle')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->modalHeading('Suppression définitive')
                        ->modalDescription('Les médias sélectionnés et leurs fichiers (original + miniatures) seront supprimés définitivement. Cette action est irréversible.')
                        ->action(function (Collection $records) {
                            foreach ($records as $media) {
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
     * Meilleure photo d'un groupe de quasi-doublons. Contrairement aux doublons
     * exacts (fichiers identiques), les photos d'une rafale diffèrent : après
     * les attaches (ne rien perdre), on privilégie la plus haute résolution,
     * puis le fichier le plus lourd (le moins recompressé), puis la plus
     * ancienne. Tris stables appliqués du critère le plus faible au plus fort.
     */
    protected static function pickRepresentative(Collection $group): Media
    {
        return $group
            ->sortBy(fn (Media $m) => $m->uploaded_at?->getTimestamp() ?? PHP_INT_MAX)
            ->sortByDesc(fn (Media $m) => $m->size ?? 0)
            ->sortByDesc(fn (Media $m) => ($m->width ?? 0) * ($m->height ?? 0))
            ->sortByDesc(fn (Media $m) => ($m->albums_count * 100) + ($m->people_count * 10) + $m->tags_count)
            ->first();
    }

    protected static function notifyTrashed(int $count): void
    {
        Notification::make()
            ->title($count.' quasi-doublon(s) mis à la corbeille')
            ->success()
            ->send();
    }

    /**
     * URL signée de la plus petite conversion disponible pour la miniature.
     */
    protected static function thumbnailUrl(Media $record): ?string
    {
        $preferred = ['thumbnail', 'small', 'medium'];

        if ($record->conversions->whereIn('conversion_name', $preferred)->isEmpty()) {
            return null;
        }

        return app(MediaService::class)->thumbnailUrl($record, $preferred);
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
            'index' => ListSimilarMedia::route('/'),
        ];
    }
}
