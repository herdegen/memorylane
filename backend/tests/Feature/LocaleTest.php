<?php

namespace Tests\Feature;

use App\Filament\Pages\SiteSettingsPage;
use App\Models\User;
use App\Support\SiteSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Langue de l'interface : défaut du site (admin), choix du membre (profil),
 * choix du visiteur avant connexion (session).
 */
class LocaleTest extends TestCase
{
    use RefreshDatabase;

    public function test_francais_par_defaut(): void
    {
        $this->get('/login')->assertOk()
            ->assertSee('<html lang="fr"', false)
            ->assertInertia(fn ($page) => $page->where('i18n.locale', 'fr')->where('i18n.default', 'fr')->etc());
    }

    public function test_langue_par_defaut_du_site(): void
    {
        SiteSettings::set('locale', 'en');

        $this->get('/login')->assertOk()->assertSee('<html lang="en"', false);

        $user = User::factory()->create();
        $this->actingAs($user)->get('/dashboard')->assertOk()
            ->assertInertia(fn ($page) => $page->where('i18n.locale', 'en')->where('i18n.preference', null)->etc());
        $this->assertSame('en', $user->preferredLocale());
    }

    public function test_le_membre_choisit_sa_langue(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/locale', ['locale' => 'en'])->assertNoContent();
        $this->assertSame('en', $user->fresh()->localePreference());
        $this->actingAs($user->fresh())->get('/dashboard')
            ->assertInertia(fn ($page) => $page->where('i18n.locale', 'en')->where('i18n.preference', 'en')->etc());

        // null : retour à la langue par défaut du site.
        $this->actingAs($user)->postJson('/locale', ['locale' => null])->assertNoContent();
        $this->assertNull($user->fresh()->localePreference());
        $this->assertSame('fr', $user->fresh()->preferredLocale());

        $this->actingAs($user)->postJson('/locale', ['locale' => 'de'])->assertUnprocessable();
    }

    public function test_le_choix_du_membre_l_emporte_sur_le_defaut_du_site(): void
    {
        SiteSettings::set('locale', 'en');
        $user = User::factory()->create();
        $user->setPreference('locale', 'fr');

        $this->actingAs($user)->get('/dashboard')
            ->assertInertia(fn ($page) => $page->where('i18n.locale', 'fr')->etc());
    }

    public function test_le_visiteur_choisit_sa_langue_avant_connexion(): void
    {
        $this->postJson('/locale', ['locale' => 'en'])->assertNoContent()->assertSessionHas('locale', 'en');
        $this->withSession(['locale' => 'en'])->get('/login')->assertSee('<html lang="en"', false);

        $this->withSession(['locale' => 'en'])->postJson('/locale', ['locale' => null])
            ->assertNoContent()->assertSessionMissing('locale');
    }

    public function test_les_messages_du_serveur_suivent_la_langue(): void
    {
        $user = User::factory()->create();
        $user->setPreference('locale', 'en');

        $this->actingAs($user->fresh())->postJson('/locale', ['locale' => 'de'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.locale.0', 'The selected locale is invalid.');
    }

    public function test_l_admin_regle_la_langue_par_defaut(): void
    {
        $admin = User::factory()->admin()->create();

        Livewire::actingAs($admin)
            ->test(SiteSettingsPage::class)
            ->assertSet('data.locale', 'fr')
            ->set('data.locale', 'en')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('en', SiteSettings::locale());
    }

    public function test_reglages_reserves_a_l_admin(): void
    {
        $this->actingAs(User::factory()->create())->get('/admin/reglages')->assertForbidden();
        $this->actingAs(User::factory()->admin()->create())->get('/admin/reglages')->assertOk();
    }

    public function test_dictionnaire_du_navigateur(): void
    {
        SiteSettings::set('locale', 'en');
        $html = $this->get('/login')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('#<script src="[^"]*/i18n/en\?v=\w+"></script>#', $html);

        $this->get('/i18n/en')->assertOk()
            ->assertHeader('Content-Type', 'application/javascript; charset=utf-8')
            ->assertSee('window.__ML_I18N__={"locale":"en","messages":{', false);
        $this->get('/i18n/fr')->assertNotFound();
        $this->get('/i18n/de')->assertNotFound();
    }

    public function test_pas_de_dictionnaire_en_francais(): void
    {
        $this->assertStringNotContainsString('/i18n/', $this->get('/login')->getContent());
    }

    public function test_les_pluriels_du_serveur_restent_en_francais_pour_un_francophone(): void
    {
        $key = ':count média|:count médias';
        $this->assertArrayHasKey($key, json_decode(file_get_contents(lang_path('en.json')), true));

        app()->setLocale('fr');
        $this->assertSame('2 médias', trans_choice($key, 2));
        $this->assertSame('1 média', trans_choice($key, 1));

        app()->setLocale('en');
        $this->assertSame('2 items', trans_choice($key, 2));
        $this->assertSame('1 item', trans_choice($key, 1));
    }

    public function test_espagnol(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->postJson('/locale', ['locale' => 'es'])->assertNoContent();
        $this->assertSame('es', $user->fresh()->preferredLocale());

        $this->actingAs($user->fresh())->get('/dashboard')
            ->assertSee('<html lang="es"', false)
            ->assertInertia(fn ($page) => $page->where('i18n.locale', 'es')->has('i18n.available.es')->etc());
        $this->get('/i18n/es')->assertOk()->assertSee('window.__ML_I18N__={"locale":"es"', false);

        app()->setLocale('es');
        $this->assertSame('2 médias', trans_choice(':count média|:count médias', 2, [], 'fr'));
        $this->assertStringNotContainsString('médias', trans_choice(':count média|:count médias', 2));
        // Messages de validation en espagnol (lang/es/validation.php), pas en français.
        $this->assertNotSame(__('validation.required', ['attribute' => 'x'], 'fr'), __('validation.required', ['attribute' => 'x']));
    }
}
