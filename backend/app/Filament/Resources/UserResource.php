<?php

namespace App\Filament\Resources;

use App\Filament\Support\UnaccentSearch;
use App\Filament\Resources\UserResource\Pages\CreateUser;
use App\Filament\Resources\UserResource\Pages\EditUser;
use App\Filament\Resources\UserResource\Pages\ListUsers;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-users';

    protected static ?string $navigationLabel = 'Utilisateurs';

    protected static ?string $modelLabel = 'Utilisateur';

    protected static ?string $pluralModelLabel = 'Utilisateurs';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required()
                    ->maxLength(255)
                    ->label('Nom'),
                TextInput::make('email')
                    ->email()
                    ->required()
                    ->maxLength(255)
                    ->label('Email'),
                DateTimePicker::make('email_verified_at')
                    ->label('Email vérifié le'),
                TextInput::make('password')
                    ->password()
                    ->maxLength(255)
                    ->dehydrateStateUsing(fn ($state) => filled($state) ? bcrypt($state) : null)
                    ->dehydrated(fn ($state) => filled($state))
                    ->required(fn (string $context): bool => $context === 'create')
                    ->label('Mot de passe')
                    ->helperText('Laissez vide pour ne pas modifier'),
                Select::make('role')
                    ->options([
                        'user' => 'Utilisateur',
                        'admin' => 'Administrateur',
                    ])
                    ->default('user')
                    ->required()
                    ->label('Rôle')
                    ->helperText('Plusieurs administrateurs possibles (succession) ; il en faut toujours au moins un.')
                    // Le dernier administrateur ne peut pas perdre son rôle.
                    ->rule(fn (?User $record) => function (string $attribute, $value, \Closure $fail) use ($record) {
                        if ($record?->isAdmin() && $value !== 'admin' && User::where('role', 'admin')->count() <= 1) {
                            $fail('C\'est le dernier administrateur : nommez-en un autre avant de lui retirer ce rôle.');
                        }
                    }),
                Select::make('person_id')
                    ->label('Personne liée')
                    ->relationship('person', 'name')
                    ->searchable()
                    ->getSearchResultsUsing(UnaccentSearch::options(fn () => \App\Models\Person::query(), ['first_name', 'last_name', 'name', 'maiden_name']))
                    ->nullable()
                    ->helperText('Lier ce compte à une personne de l\'arbre généalogique'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Nom')
                    ->searchable(query: UnaccentSearch::columns(['name', 'email']))
                    ->sortable(),
                TextColumn::make('email')
                    ->label('Email')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('role')
                    ->label('Rôle')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'admin' => 'danger',
                        default => 'gray',
                    })
                    ->sortable(),
                TextColumn::make('memorial_at')
                    ->label('Statut')
                    ->formatStateUsing(fn ($state) => $state ? '🕯️ En mémoire' : null)
                    ->placeholder('Actif')
                    ->toggleable(),
                TextColumn::make('person.name')
                    ->label('Personne liée')
                    ->placeholder('—')
                    ->searchable(query: UnaccentSearch::relation('person', ['first_name', 'last_name', 'name', 'maiden_name'])),
                // Ouverte au moins une fois depuis l'icône de l'écran d'accueil.
                TextColumn::make('app_installed')
                    ->label('App installée')
                    ->state(fn (User $record) => data_get($record->preferences, 'onboarding.pwa'))
                    ->dateTime('d/m/Y')
                    ->placeholder('—'),
                TextColumn::make('email_verified_at')
                    ->label('Vérifié le')
                    ->dateTime('d/m/Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')
                    ->label('Créé le')
                    ->dateTime('d/m/Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('role')
                    ->options([
                        'user' => 'Utilisateur',
                        'admin' => 'Administrateur',
                    ])
                    ->label('Rôle'),
            ])
            ->recordActions([
                Action::make('impersonate')
                    ->label('Se connecter en tant que')
                    ->icon('heroicon-o-eye')
                    ->color('gray')
                    ->visible(fn (User $record) => auth()->user()?->isAdmin() && ! $record->is(auth()->user()))
                    ->requiresConfirmation()
                    ->modalHeading(fn (User $record) => 'Voir l\'appli en tant que '.$record->name)
                    ->modalDescription('Vous verrez l\'appli exactement comme cette personne. Ce que vous faites pendant ce temps est fait en son nom. Un bandeau en haut de page permet de revenir à votre compte.')
                    ->modalSubmitActionLabel('Se connecter')
                    ->action(function (User $record, $livewire) {
                        app(\App\Services\Impersonation::class)->start(auth()->user(), $record);
                        $livewire->redirect($record->simple_mode ? '/simple' : '/dashboard');
                    }),
                // Membre décédé : on conserve tout, on coupe connexion et notifications.
                Action::make('memorial')
                    ->label('En mémoire')
                    ->icon('heroicon-o-heart')
                    ->color('gray')
                    ->visible(fn (User $record) => ! $record->isMemorial() && $record->id !== auth()->id())
                    ->requiresConfirmation()
                    ->modalHeading(fn (User $record) => 'Conserver le compte de '.$record->name.' en mémoire')
                    ->modalDescription('Plus de connexion ni de notification. Rien n\'est supprimé : ses photos, albums, souvenirs racontés et sa fiche restent visibles comme aujourd\'hui. Réversible.')
                    ->modalSubmitActionLabel('Passer en mémoire')
                    ->action(function (User $record) {
                        abort_if($record->isAdmin() && User::where('role', 'admin')->whereNull('memorial_at')->count() <= 1, 422, 'Dernier administrateur : nommez-en un autre d\'abord.');
                        $record->markMemorial();
                        \Filament\Notifications\Notification::make()->title('Compte conservé en mémoire')->success()->send();
                    }),
                // Fermer un compte (départ décidé par l'admin) : mêmes choix
                // qu'un départ, appliqué tout de suite ; la personne est prévenue.
                Action::make('removeAccount')
                    ->label('Retirer de MemoryLane')
                    ->icon('heroicon-o-user-minus')
                    ->color('danger')
                    ->visible(fn (User $record) => $record->id !== auth()->id())
                    ->modalHeading(fn (User $record) => 'Retirer '.$record->name.' de MemoryLane')
                    ->modalDescription('Sa fiche reste dans l\'arbre ; les fiches, moments et récits qu\'il ou elle a créés passent à un administrateur. Irréversible.')
                    ->schema([
                        \Filament\Forms\Components\Radio::make('photos')
                            ->label('Ses photos et albums')
                            ->options([
                                'keep_shared' => 'Garder ce qui est partagé (repris par un administrateur), retirer le privé',
                                'keep_all' => 'Tout garder (repris par un administrateur)',
                                'remove_all' => 'Tout retirer',
                            ])
                            ->default('keep_shared')
                            ->required(),
                        \Filament\Forms\Components\Radio::make('voice_notes')
                            ->label('Ses souvenirs racontés')
                            ->options(['keep' => 'Les garder', 'remove' => 'Les retirer'])
                            ->default('keep')
                            ->required(),
                        \Filament\Forms\Components\Toggle::make('untag_me')
                            ->label('Retirer son nom des photos des autres')
                            ->default(false),
                        \Filament\Forms\Components\TextInput::make('confirm')
                            ->label(fn (User $record) => 'Tapez « '.explode(' ', trim($record->name))[0].' » pour confirmer')
                            ->required()
                            ->rule(fn (User $record) => function (string $attribute, $value, \Closure $fail) use ($record) {
                                if (mb_strtolower(trim((string) $value)) !== mb_strtolower(explode(' ', trim($record->name))[0])) {
                                    $fail('Le prénom ne correspond pas.');
                                }
                            }),
                    ])
                    ->modalSubmitActionLabel('Retirer définitivement')
                    ->action(function (User $record, array $data) {
                        $departures = app(\App\Services\DepartureService::class);
                        if ($record->isAdmin() && $departures->fallbackOwner($record) === null) {
                            \Filament\Notifications\Notification::make()->title('Dernier administrateur : nommez-en un autre d\'abord.')->danger()->send();

                            return;
                        }
                        $record->forceFill(['departure_options' => [
                            'photos' => $data['photos'],
                            'voice_notes' => $data['voice_notes'],
                            'untag_me' => (bool) $data['untag_me'],
                        ]])->save();
                        if (! $record->isMemorial() && $record->email) {
                            try {
                                $record->notifyNow(new \App\Notifications\AccountRemovedByAdmin(auth()->user()->name));
                            } catch (\Throwable $e) {
                                report($e); // le mail ne doit pas bloquer le retrait
                            }
                        }
                        $name = $record->name;
                        $departures->finalizeAccountDeparture($record->fresh());
                        \Filament\Notifications\Notification::make()->title("{$name} a été retiré·e de MemoryLane")->success()->send();
                    }),
                Action::make('unmemorial')
                    ->label('Réactiver')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('gray')
                    ->visible(fn (User $record) => $record->isMemorial())
                    ->requiresConfirmation()
                    ->action(fn (User $record) => $record->unmarkMemorial()),
                EditAction::make(),
            ])
            // Pas de suppression ici : un départ passe par la procédure qui
            // transfère ou retire d'abord photos, albums, fiches et cercles
            // (la base refuse de supprimer un compte qui en possède encore).
            ->toolbarActions([]);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListUsers::route('/'),
            'create' => CreateUser::route('/create'),
            'edit' => EditUser::route('/{record}/edit'),
        ];
    }
}
