<?php

namespace App\Filament\Resources;

use App\Filament\Resources\UserResource\Pages\CreateUser;
use App\Filament\Resources\UserResource\Pages\EditUser;
use App\Filament\Resources\UserResource\Pages\ListUsers;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
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
                    ->label('Rôle'),
                Select::make('person_id')
                    ->label('Personne liée')
                    ->relationship('person', 'name')
                    ->searchable()
                    ->preload()
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
                    ->searchable()
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
                TextColumn::make('person.name')
                    ->label('Personne liée')
                    ->placeholder('—')
                    ->searchable(),
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
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
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
