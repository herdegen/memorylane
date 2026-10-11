<?php

namespace App\Filament\Resources;

use App\Filament\Resources\DuplicateFaceResource\Pages\ListDuplicateFaces;
use App\Models\DetectedFace;
use App\Services\Vision\DuplicateFaceResolver;
use App\Services\Vision\FaceMatcher;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Revue des visages en double : une même personne identifiée sur plusieurs
 * visages d'une photo. Chaque groupe (photo + personne) montre ses visages,
 * le plus ressemblant aux autres photos de la personne étant suggéré
 * (DuplicateFaceResolver). « Garder celui-ci » rend les autres visages à
 * identifier ; « Pas cette personne » retire l'identification de ce visage.
 */
class DuplicateFaceResource extends Resource
{
    protected static ?string $model = DetectedFace::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-users';

    protected static ?string $navigationLabel = 'Visages en double';

    protected static string|\UnitEnum|null $navigationGroup = 'Nettoyage';

    protected static ?string $modelLabel = 'visage en double';

    protected static ?string $pluralModelLabel = 'visages en double';

    protected static ?string $breadcrumb = 'Visages en double';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('detected_faces.status', 'matched')
            ->whereNotNull('detected_faces.person_id')
            ->whereExists(function ($q) {
                $q->selectRaw('1')->from('detected_faces as sib')
                    ->whereColumn('sib.media_id', 'detected_faces.media_id')
                    ->whereColumn('sib.person_id', 'detected_faces.person_id')
                    ->whereColumn('sib.id', '!=', 'detected_faces.id')
                    ->where('sib.status', 'matched');
            })
            ->select('detected_faces.*')
            ->selectRaw("detected_faces.media_id || ':' || detected_faces.person_id as dup_key")
            ->with(['person:id,name', 'media:id,original_name,taken_at']);
    }

    public static function getNavigationBadge(): ?string
    {
        $pairs = app(DuplicateFaceResolver::class)->pairs()->count();

        return $pairs > 0 ? (string) $pairs : null;
    }

    public static function table(Table $table): Table
    {
        $analysis = fn () => app(DuplicateFaceResolver::class)->analysis();

        return $table
            ->groups([
                Group::make('dup_key')
                    ->label('Photo et personne')
                    ->getTitleFromRecordUsing(fn (DetectedFace $r): string => ($r->person?->name ?? '?').' · '.($r->media?->original_name ?? 'photo'))
                    ->getDescriptionFromRecordUsing(fn (DetectedFace $r): ?string => $r->media?->taken_at?->format('d/m/Y')),
            ])
            ->defaultGroup('dup_key')
            ->paginated([25, 50, 100])
            ->columns([
                ImageColumn::make('crop')
                    ->label('')
                    ->getStateUsing(fn (DetectedFace $r) => url("/vision/faces/{$r->id}/crop"))
                    ->height(64)
                    ->square(),
                TextColumn::make('score')
                    ->label('Ressemblance')
                    ->getStateUsing(function (DetectedFace $r) use ($analysis) {
                        $row = $analysis()[$r->id] ?? null;
                        if (! $row || $row['score'] === null) {
                            return '—';
                        }
                        // Distance → pourcentage lisible (100 % = identique).
                        return round(max(0, 1 - $row['score'] / FaceMatcher::MATCH_THRESHOLD) * 100).' %';
                    })
                    ->badge()
                    ->color(fn (DetectedFace $r) => ($analysis()[$r->id]['suggested'] ?? false) ? 'success' : 'gray'),
                TextColumn::make('suggestion')
                    ->label('Suggestion')
                    ->getStateUsing(function (DetectedFace $r) use ($analysis) {
                        $row = $analysis()[$r->id] ?? null;

                        return match (true) {
                            ! $row || ! $row['suggested'] => '',
                            $row['clear'] => 'À garder',
                            default => 'À garder ? (écart faible)',
                        };
                    })
                    ->color(fn (DetectedFace $r) => ($analysis()[$r->id]['clear'] ?? false) ? 'success' : 'warning'),
            ])
            ->recordActions([
                Action::make('keep')
                    ->label('Garder celui-ci')
                    ->icon('heroicon-o-check')
                    ->color('success')
                    ->action(function (DetectedFace $record) {
                        $n = app(DuplicateFaceResolver::class)->keep($record);
                        Notification::make()->title("Gardé ; {$n} autre(s) visage(s) à identifier")->success()->send();
                    }),
                Action::make('reject')
                    ->label('Pas cette personne')
                    ->icon('heroicon-o-x-mark')
                    ->color('gray')
                    ->action(function (DetectedFace $record) {
                        app(FaceMatcher::class)->disassociate($record);
                        Notification::make()->title('Identification retirée')->success()->send();
                    }),
                Action::make('open')
                    ->label('Photo')
                    ->icon('heroicon-o-eye')
                    ->color('gray')
                    ->url(fn (DetectedFace $r) => '/media/'.$r->media_id)
                    ->openUrlInNewTab(),
            ]);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDuplicateFaces::route('/'),
        ];
    }
}
