<?php

namespace App\Filament\Resources;

use App\Filament\Support\UnaccentSearch;
use App\Filament\Resources\InvitationResource\Pages\CreateInvitation;
use App\Filament\Resources\InvitationResource\Pages\ListInvitations;
use App\Mail\InvitationMail;
use App\Models\Household;
use App\Models\Invitation;
use App\Models\Person;
use App\Models\User;
use App\Services\InvitationService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;

/**
 * Invitations à créer son accès (mail de présentation + lien /bienvenue),
 * envoyées depuis le Gmail de l'admin.
 */
class InvitationResource extends Resource
{
    protected static ?string $model = Invitation::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-envelope';

    protected static ?string $navigationLabel = 'Invitations';

    protected static ?string $modelLabel = 'Invitation';

    protected static ?string $pluralModelLabel = 'Invitations';

    protected static ?int $navigationSort = 1;

    public const STATUS_LABELS = [
        'pending' => 'En attente',
        'accepted' => 'Acceptée',
        'expired' => 'Expirée',
        'revoked' => 'Révoquée',
    ];

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('person_id')
                    ->label('Fiche dans l\'arbre')
                    ->helperText('Seules les personnes vivantes, sans compte et sans invitation en cours sont proposées.')
                    ->searchable()
                    ->getSearchResultsUsing(fn (string $search) => static::eligiblePeople()
                        ->whereUnaccentLike('name', $search)
                        ->limit(50)
                        ->get()
                        ->mapWithKeys(fn (Person $p) => [$p->id => static::personLabel($p)]))
                    ->getOptionLabelUsing(fn ($value) => ($p = Person::find($value)) ? static::personLabel($p) : null)
                    ->required(),
                TextInput::make('email')
                    ->label('Adresse e-mail')
                    ->email()
                    ->required()
                    ->maxLength(255)
                    ->helperText(fn () => config('mail.invitations.admin_mailer')
                        ? 'Le mail partira de '.config('mail.invitations.admin_from_address').'.'
                        : '⚠️ Gmail non configuré (GMAIL_APP_PASSWORD) : le mail partira de l\'adresse par défaut de MemoryLane.'),
                Select::make('household_id')
                    ->label('Foyer à rejoindre')
                    ->options(fn () => Household::orderBy('name')->pluck('name', 'id'))
                    ->default(fn () => Household::welcome()?->id)
                    ->helperText('La personne verra les photos partagées dans ce foyer.')
                    ->nullable(),
                Toggle::make('formal')
                    ->label('Vouvoyer dans le mail')
                    ->default(false),
                Textarea::make('message')
                    ->label('Petit mot personnel (facultatif)')
                    ->helperText('Affiché en tête du mail, entre guillemets.')
                    ->rows(3)
                    ->maxLength(1000),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('person.name')
                    ->label('Fiche')
                    ->searchable(query: UnaccentSearch::relation('person', ['first_name', 'last_name', 'name', 'maiden_name']))
                    ->url(fn (Invitation $record) => '/people/'.$record->person_id)
                    ->openUrlInNewTab(),
                TextColumn::make('email')
                    ->label('E-mail')
                    ->searchable()
                    ->copyable(),
                TextColumn::make('status')
                    ->label('Statut')
                    ->state(fn (Invitation $record) => $record->last_error && ! $record->sent_at
                        ? 'Échec d\'envoi'
                        : self::STATUS_LABELS[$record->status()])
                    ->badge()
                    ->color(fn (Invitation $record) => match (true) {
                        $record->last_error && ! $record->sent_at => 'danger',
                        $record->status() === 'accepted' => 'success',
                        $record->status() === 'pending' => 'warning',
                        default => 'gray',
                    })
                    ->tooltip(fn (Invitation $record) => $record->last_error),
                TextColumn::make('inviter.name')
                    ->label('Invité par')
                    ->placeholder('—'),
                TextColumn::make('sent_at')
                    ->label('Envoyée le')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('—')
                    ->sortable(),
                TextColumn::make('accepted_at')
                    ->label('Acceptée le')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('—')
                    ->sortable(),
                TextColumn::make('household.name')
                    ->label('Foyer')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('formal')
                    ->label('Ton')
                    ->formatStateUsing(fn ($state) => $state ? 'vous' : 'tu')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('state')
                    ->label('Statut')
                    ->options(self::STATUS_LABELS)
                    ->query(fn (Builder $query, array $data) => match ($data['value'] ?? null) {
                        'pending' => $query->open(),
                        'accepted' => $query->whereNotNull('accepted_at'),
                        'revoked' => $query->whereNull('accepted_at')->whereNotNull('revoked_at'),
                        'expired' => $query->whereNull('accepted_at')->whereNull('revoked_at')->where('expires_at', '<=', now()),
                        default => $query,
                    }),
            ])
            ->recordActions([
                Action::make('preview')
                    ->label('Aperçu')
                    ->icon('heroicon-o-eye')
                    ->color('gray')
                    ->url(fn (Invitation $record) => route('invitations.preview', $record))
                    ->openUrlInNewTab(),
                Action::make('copyLink')
                    ->label('Lien')
                    ->icon('heroicon-o-link')
                    ->color('gray')
                    ->visible(fn (Invitation $record) => $record->isUsable())
                    ->modalHeading('Lien d\'invitation')
                    ->modalDescription('À envoyer par SMS ou WhatsApp si le mail n\'arrive pas. Il est personnel : la personne crée son accès avec.')
                    ->modalContent(fn (Invitation $record) => new HtmlString(
                        '<input type="text" readonly onclick="this.select()" value="'.e($record->url()).'" '
                        .'style="width:100%;padding:8px 10px;border:1px solid #d6d3d1;border-radius:8px;font-size:13px;">'
                    ))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Fermer'),
                Action::make('resend')
                    ->label('Renvoyer')
                    ->icon('heroicon-o-paper-airplane')
                    ->color('primary')
                    ->visible(fn (Invitation $record) => $record->accepted_at === null)
                    ->requiresConfirmation()
                    ->modalHeading('Renvoyer l\'invitation')
                    ->modalDescription('Un nouveau mail part avec un nouveau lien, valable 30 jours. L\'ancien lien ne fonctionnera plus.')
                    ->action(fn (Invitation $record) => static::sendWithNotification($record)),
                Action::make('previewToMe')
                    ->label('M\'envoyer un aperçu')
                    ->icon('heroicon-o-inbox-arrow-down')
                    ->color('gray')
                    ->action(function (Invitation $record) {
                        try {
                            app(InvitationService::class)->sendPreviewTo($record, auth()->user()->email);
                            Notification::make()->title('Aperçu envoyé à '.auth()->user()->email)->success()->send();
                        } catch (\Throwable $e) {
                            Notification::make()->title('Échec de l\'envoi')->body($e->getMessage())->danger()->send();
                        }
                    }),
                Action::make('revoke')
                    ->label('Révoquer')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (Invitation $record) => $record->status() === 'pending')
                    ->requiresConfirmation()
                    ->modalDescription('Le lien envoyé ne fonctionnera plus.')
                    ->action(function (Invitation $record) {
                        app(InvitationService::class)->revoke($record);
                        Notification::make()->title('Invitation révoquée')->success()->send();
                    }),
            ]);
    }

    /**
     * Envoie (ou renvoie) une invitation et notifie le résultat.
     */
    public static function sendWithNotification(Invitation $invitation): void
    {
        try {
            app(InvitationService::class)->send($invitation);
            Notification::make()
                ->title('Invitation envoyée à '.$invitation->email)
                ->body(InvitationMail::sentFromAdminGmail($invitation->fresh())
                    ? 'Depuis '.config('mail.invitations.admin_from_address').'.'
                    : 'Depuis l\'adresse par défaut de MemoryLane.')
                ->success()
                ->send();
        } catch (\Throwable $e) {
            Notification::make()
                ->title('L\'envoi a échoué')
                ->body($e->getMessage())
                ->danger()
                ->persistent()
                ->send();
        }
    }

    /**
     * Fiches pouvant recevoir une invitation : vivantes, sans compte, sans
     * invitation en cours.
     */
    public static function eligiblePeople(): Builder
    {
        return Person::query()
            ->whereNull('death_date')
            ->where(fn ($q) => $q->whereNull('birth_date')->orWhere('birth_date', '>', now()->subYears(110)))
            ->whereNotIn('id', User::whereNotNull('person_id')->select('person_id'))
            ->whereNotIn('id', Invitation::open()->select('person_id'))
            ->orderBy('name');
    }

    public static function personLabel(Person $person): string
    {
        return $person->name
            .($person->maiden_name ? " ({$person->maiden_name})" : '')
            .($person->birth_date ? ' — '.$person->birth_date->year : '');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListInvitations::route('/'),
            'create' => CreateInvitation::route('/create'),
        ];
    }
}
