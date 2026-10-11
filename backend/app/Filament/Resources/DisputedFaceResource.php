<?php

namespace App\Filament\Resources;

use App\Filament\Resources\DisputedFaceResource\Pages\ListDisputedFaces;
use App\Models\DetectedFace;
use App\Models\Person;
use App\Services\Vision\DisputedFaceResolver;
use App\Services\Vision\FaceMatcher;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Identifications existantes qu'InsightFace remet en question (non confirmées
 * par un humain) : ressemble plus à une autre personne, à personne, ou âge
 * estimé incompatible. Confirmer, remplacer par la suggestion ou retirer.
 */
class DisputedFaceResource extends Resource
{
    protected static ?string $model = DetectedFace::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-question-mark-circle';

    protected static ?string $navigationLabel = 'Identifications à vérifier';

    protected static string|\UnitEnum|null $navigationGroup = 'Nettoyage';

    protected static ?string $modelLabel = 'identification à vérifier';

    protected static ?string $pluralModelLabel = 'identifications à vérifier';

    protected static ?string $breadcrumb = 'Identifications à vérifier';

    private const REASONS = [
        'other' => 'Ressemble plus à quelqu\'un d\'autre',
        'unlike' => 'Ne ressemble pas à la personne',
        'age' => 'Âge estimé incompatible',
    ];

    public static function getEloquentQuery(): Builder
    {
        // Ids triés du désaccord le plus franc au plus faible.
        $ids = collect(app(DisputedFaceResolver::class)->analysis())->sortByDesc('strength')->keys()->all();

        return parent::getEloquentQuery()
            ->whereIn('detected_faces.id', $ids ?: ['00000000-0000-0000-0000-000000000000'])
            ->when($ids, fn ($q) => $q->orderByRaw('array_position(?::uuid[], detected_faces.id)', ['{'.implode(',', $ids).'}']))
            ->with(['person:id,name', 'media:id,original_name,taken_at']);
    }

    public static function getNavigationBadge(): ?string
    {
        $n = count(app(DisputedFaceResolver::class)->analysis());

        return $n > 0 ? (string) $n : null;
    }

    private static function row(DetectedFace $r): ?array
    {
        return app(DisputedFaceResolver::class)->analysis()[$r->id] ?? null;
    }

    private static function suggested(DetectedFace $r): ?Person
    {
        $pid = self::row($r)['best_person'] ?? null;

        return $pid ? Person::find($pid) : null;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->paginated([25, 50, 100])
            ->columns([
                ImageColumn::make('crop')
                    ->label('')
                    ->getStateUsing(fn (DetectedFace $r) => url("/vision/faces/{$r->id}/crop"))
                    ->height(64)->square(),
                TextColumn::make('person.name')->label('Identifié comme')
                    ->description(fn (DetectedFace $r) => $r->media?->original_name.($r->media?->taken_at ? ' · '.$r->media->taken_at->format('Y') : '')),
                TextColumn::make('reason')->label('Pourquoi')
                    ->getStateUsing(fn (DetectedFace $r) => self::REASONS[self::row($r)['reason'] ?? ''] ?? '')
                    ->description(function (DetectedFace $r) {
                        $row = self::row($r);
                        if (($row['reason'] ?? null) === 'age') {
                            return 'Écart de '.abs($row['age_gap']).' ans (âge estimé '.$r->age_estimate.' ans)';
                        }

                        return ($p = self::suggested($r)) ? 'InsightFace : '.$p->name : null;
                    })
                    ->badge()->color('warning'),
            ])
            ->filters([
                SelectFilter::make('reason')->label('Motif')->options(self::REASONS)
                    ->query(function (Builder $query, array $data) {
                        if (! $data['value']) {
                            return $query;
                        }
                        $ids = collect(app(DisputedFaceResolver::class)->analysis())->where('reason', $data['value'])->keys()->all();

                        return $query->whereIn('detected_faces.id', $ids ?: ['00000000-0000-0000-0000-000000000000']);
                    }),
            ])
            ->recordActions([
                Action::make('confirm')
                    ->label(fn (DetectedFace $r) => 'C\'est bien '.strtok((string) $r->person?->name, ' '))
                    ->icon('heroicon-o-check')->color('success')
                    ->action(function (DetectedFace $record) {
                        $record->forceFill(['verified_at' => now()])->save();
                        Notification::make()->title('Identification confirmée')->success()->send();
                    }),
                Action::make('replace')
                    ->label(fn (DetectedFace $r) => ($p = self::suggested($r)) ? 'Plutôt '.strtok($p->name, ' ') : 'Remplacer')
                    ->icon('heroicon-o-arrow-path')->color('primary')
                    ->visible(fn (DetectedFace $r) => self::suggested($r) !== null)
                    ->requiresConfirmation()
                    ->modalDescription(fn (DetectedFace $r) => 'Le visage sera associé à '.self::suggested($r)?->name.' (garde-fous de date et de doublon appliqués).')
                    ->action(function (DetectedFace $record) {
                        $person = self::suggested($record);
                        try {
                            app(FaceMatcher::class)->applyMatch($record, $person->id, verified: true);
                            Notification::make()->title('Associé à '.$person->name)->success()->send();
                        } catch (ValidationException $e) {
                            Notification::make()->title(collect($e->errors())->flatten()->first())->danger()->send();
                        }
                    }),
                Action::make('remove')
                    ->label('Retirer')->icon('heroicon-o-x-mark')->color('gray')
                    ->action(function (DetectedFace $record) {
                        app(FaceMatcher::class)->disassociate($record);
                        Notification::make()->title('Identification retirée')->success()->send();
                    }),
                Action::make('open')->label('Photo')->icon('heroicon-o-eye')->color('gray')
                    ->url(fn (DetectedFace $r) => '/media/'.$r->media_id)->openUrlInNewTab(),
            ])
            ->toolbarActions([
                BulkAction::make('confirmAll')
                    ->label('Confirmer la sélection')->icon('heroicon-o-check')->color('success')
                    ->requiresConfirmation()
                    ->action(function (Collection $records) {
                        $records->each(fn (DetectedFace $f) => $f->forceFill(['verified_at' => now()])->save());
                        Notification::make()->title($records->count().' identification(s) confirmée(s)')->success()->send();
                    })
                    ->deselectRecordsAfterCompletion(),
            ]);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return ['index' => ListDisputedFaces::route('/')];
    }
}
