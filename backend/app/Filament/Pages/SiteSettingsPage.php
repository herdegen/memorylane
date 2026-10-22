<?php

namespace App\Filament\Pages;

use App\Support\Locales;
use App\Support\SiteSettings;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Schema;

/**
 * Réglages du site : langue de l'interface par défaut (visiteurs et membres
 * qui n'ont pas choisi de langue dans leur profil).
 */
class SiteSettingsPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static ?string $navigationLabel = 'Réglages du site';

    protected static ?string $title = 'Réglages du site';

    protected static ?string $slug = 'reglages';

    protected static ?int $navigationSort = 100;

    /** @var array<string,mixed>|null */
    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill(['locale' => SiteSettings::locale()]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('locale')
                    ->label('Langue par défaut de l’interface')
                    ->options(Locales::SUPPORTED)
                    ->required()
                    ->selectablePlaceholder(false)
                    ->helperText('Pour la page de connexion et les membres qui n’ont pas choisi de langue dans leur profil. Seul le texte du site est traduit : noms, albums et récits restent tels quels.'),
            ])
            ->statePath('data');
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])
                ->id('form')
                ->livewireSubmitHandler('save')
                ->footer([
                    Actions::make([
                        Action::make('save')->label('Enregistrer')->submit('save'),
                    ]),
                ]),
        ]);
    }

    public function save(): void
    {
        $data = $this->form->getState();
        SiteSettings::set('locale', $data['locale']);

        Notification::make()->title('Réglages enregistrés')->success()->send();
    }
}
