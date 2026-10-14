<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PersonFaceResource\Pages\ListPersonFaces;
use App\Models\DetectedFace;
use App\Models\Person;
use App\Services\Vision\FaceMatcher;
use App\Services\Vision\PersonFaceCandidates;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Identification en lot par personne : on choisit quelqu'un, on voit tous
 * les visages non identifiés qui lui ressemblent (cf. PersonFaceCandidates)
 * et on valide en masse ceux qui sont bien elle.
 */
class PersonFaceResource extends Resource
{
    protected static ?string $model = DetectedFace::class;

    protected static ?string $slug = 'person-faces';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-user-plus';

    protected static ?string $navigationLabel = 'Identifier par personne';

    protected static string|\UnitEnum|null $navigationGroup = 'Nettoyage';

    protected static ?string $modelLabel = 'visage';

    protected static ?string $pluralModelLabel = 'visages';

    protected static ?string $breadcrumb = 'Identifier par personne';

    /** Mémo par requête : distances et suggestions (calculs coûteux). */
    private static array $memo = [];

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('media:id,original_name,taken_at,user_id');
    }

    private static function personId($livewire): ?string
    {
        return data_get($livewire?->tableFilters, 'person.value') ?: null;
    }

    private static function person($livewire): ?Person
    {
        $id = self::personId($livewire);

        return $id ? (self::$memo["person:{$id}"] ??= Person::find($id)) : null;
    }

    private static function firstName($livewire): string
    {
        return strtok(self::person($livewire)?->name ?? '', ' ') ?: 'elle';
    }

    private static function distance(DetectedFace $face, $livewire): ?float
    {
        $id = self::personId($livewire);

        return $id ? ((self::$memo["candidates:{$id}"] ??= app(PersonFaceCandidates::class)->for($id))[$face->id] ?? null) : null;
    }

    /** Personne que le moteur préfère pour ce visage, si ce n'est pas celle choisie. */
    private static function rival(DetectedFace $face, $livewire): ?array
    {
        if (! $face->media) {
            return null;
        }
        $best = (self::$memo["rival:{$face->id}"] ??= app(FaceMatcher::class)
            ->rankedCandidates($face, app(FaceMatcher::class)->matchThreshold(), $face->media->user_id)[0] ?? false);

        return $best && $best['person']['id'] !== self::personId($livewire) ? $best : null;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->paginated([25, 50, 100])
            ->defaultPaginationPageOption(50)
            ->deferFilters(false)
            ->filters([
                SelectFilter::make('person')
                    ->label('Personne')
                    ->placeholder('Choisir une personne…')
                    ->options(fn () => self::personOptions())
                    ->searchable()
                    ->query(function (Builder $query, array $data) {
                        if (! $data['value']) {
                            return $query->whereRaw('1 = 0');
                        }
                        $ids = array_keys(app(PersonFaceCandidates::class)->for($data['value']));

                        return $query
                            ->whereIn('detected_faces.id', $ids ?: ['00000000-0000-0000-0000-000000000000'])
                            ->when($ids, fn ($q) => $q->orderByRaw('array_position(?::uuid[], detected_faces.id)', ['{'.implode(',', $ids).'}']));
                    }),
            ], layout: FiltersLayout::AboveContent)
            ->emptyStateHeading(fn ($livewire) => self::personId($livewire) ? 'Aucun visage non identifié ne lui ressemble assez' : 'Choisissez une personne')
            ->emptyStateDescription(fn ($livewire) => self::personId($livewire) ? null : 'Ses visages encore non identifiés apparaîtront ici, du plus ressemblant au moins ressemblant.')
            ->columns([
                ImageColumn::make('crop')
                    ->label('')
                    ->getStateUsing(fn (DetectedFace $r) => url("/vision/faces/{$r->id}/crop"))
                    ->height(72)->square(),
                TextColumn::make('likeness')->label('Ressemblance')
                    ->getStateUsing(function (DetectedFace $r, $livewire) {
                        $d = self::distance($r, $livewire);

                        return $d === null ? null : round(app(FaceMatcher::class)->score($d) * 100).' %';
                    })
                    ->badge()
                    ->color(fn (?string $state) => (int) $state >= 60 ? 'success' : ((int) $state >= 30 ? 'warning' : 'gray')),
                TextColumn::make('media.original_name')->label('Photo')->wrap()
                    ->description(fn (DetectedFace $r) => $r->media?->taken_at?->format('Y')),
                TextColumn::make('rival')->label('Le moteur préfère')
                    ->getStateUsing(fn (DetectedFace $r, $livewire) => ($b = self::rival($r, $livewire)) ? $b['person']['name'] : null)
                    ->placeholder('—')
                    ->badge()->color('warning')->wrap(),
            ])
            ->recordActions([
                Action::make('confirm')
                    ->label(fn ($livewire) => 'C\'est '.self::firstName($livewire))
                    ->icon('heroicon-o-check')->color('success')
                    ->action(function (DetectedFace $record, $livewire) {
                        $n = self::apply(collect([$record]), $livewire);
                        Notification::make()->title($n ? 'Visage identifié' : 'Refusé (déjà sur la photo ?)')->{$n ? 'success' : 'warning'}()->send();
                    }),
                Action::make('open')->label('Photo')->icon('heroicon-o-eye')->color('gray')
                    ->url(fn (DetectedFace $r) => '/media/'.$r->media_id)->openUrlInNewTab(),
            ])
            ->toolbarActions([
                BulkAction::make('confirmAll')
                    ->label(fn ($livewire) => 'C\'est '.self::firstName($livewire))
                    ->icon('heroicon-o-check')->color('success')
                    ->requiresConfirmation()
                    ->modalDescription(fn ($livewire) => 'Les visages cochés seront identifiés comme '.(self::person($livewire)?->name ?? '').' (garde-fous de date et de doublon appliqués).')
                    ->action(function (Collection $records, $livewire) {
                        $done = self::apply($records, $livewire);
                        $skipped = $records->count() - $done;
                        Notification::make()
                            ->title($done.' visage(s) identifié(s)'.($skipped ? ", {$skipped} refusé(s) (déjà sur la photo…)" : ''))
                            ->{$skipped ? 'warning' : 'success'}()->send();
                    })
                    ->deselectRecordsAfterCompletion(),
                BulkAction::make('strangers')
                    ->label('Inconnus / hors famille')->icon('heroicon-o-user-minus')->color('gray')
                    ->requiresConfirmation()
                    ->modalDescription('Les visages restent affichés « inconnu » et ne sont plus proposés.')
                    ->action(function (Collection $records) {
                        $records->each(fn (DetectedFace $f) => app(FaceMatcher::class)->disassociate($f));
                        Notification::make()->title($records->count().' visage(s) marqué(s) inconnu(s)')->success()->send();
                    })
                    ->deselectRecordsAfterCompletion(),
                BulkAction::make('notFaces')
                    ->label('Pas des visages')->icon('heroicon-o-eye-slash')->color('gray')
                    ->requiresConfirmation()
                    ->modalDescription('Les cadres sont masqués (dos, motif, flou…).')
                    ->action(function (Collection $records) {
                        $records->each(fn (DetectedFace $f) => app(FaceMatcher::class)->disassociate($f, 'dismissed'));
                        Notification::make()->title($records->count().' cadre(s) masqué(s)')->success()->send();
                    })
                    ->deselectRecordsAfterCompletion(),
            ]);
    }

    /**
     * Personnes triées par nombre de visages dont elles sont la suggestion du
     * moteur (affiché entre parenthèses), puis par nom.
     */
    private static function personOptions(): array
    {
        $counts = app(PersonFaceCandidates::class)->counts()['counts'];
        $names = Person::query()->orderBy('name')->pluck('name', 'id')->all();
        uksort($names, fn ($a, $b) => (($counts[$b] ?? 0) <=> ($counts[$a] ?? 0)) ?: strcasecmp($names[$a], $names[$b]));

        return collect($names)->map(fn ($name, $id) => isset($counts[$id]) ? "{$name} ({$counts[$id]})" : $name)->all();
    }

    /** Identifie les visages comme la personne choisie ; renvoie le nombre réussi. */
    private static function apply(Collection $faces, $livewire): int
    {
        $personId = self::personId($livewire);
        if (! $personId) {
            return 0;
        }
        $done = 0;
        foreach ($faces as $face) {
            try {
                app(FaceMatcher::class)->applyMatch($face, $personId, verified: true);
                $done++;
            } catch (ValidationException) {
                // Déjà identifiée sur cette photo, ou impossible à cette date.
            }
        }

        return $done;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return ['index' => ListPersonFaces::route('/')];
    }
}
