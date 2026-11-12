<?php

namespace Tests\Feature;

use App\Models\Household;
use App\Models\User;
use App\Services\S3Service;
use App\Support\Install\EnvFile;
use App\Support\Install\Installer;
use Dotenv\Dotenv;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Assistant d'installation chez soi : introuvable hors installation, code du
 * terminal, écriture de la configuration, installation complète. Jamais le
 * vrai .env ni le vrai dossier d'état : tout passe par un dossier temporaire.
 */
class InstallerTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;
    private string $envPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/ml-install-'.uniqid();
        File::ensureDirectoryExists($this->dir);
        config(['memorylane.install_dir' => $this->dir]);

        $this->envPath = $this->dir.'/backend.env';
        $template = file_get_contents(resource_path('install/backend.env.template'));
        file_put_contents($this->envPath, strtr($template, [
            '__APP_KEY__' => 'base64:'.base64_encode(random_bytes(32)),
            '__APP_URL__' => 'http://192.168.1.20:8080',
            '__DB_PASSWORD__' => 'x', '__MEILI_KEY__' => 'y',
        ]));
        $this->app->instance(EnvFile::class, new EnvFile($this->envPath));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    private function startInstall(): void
    {
        file_put_contents($this->dir.'/token', 'ABCD2345');
    }

    private function unlock(): void
    {
        $this->startInstall();
        $this->postJson('/install/acces', ['token' => 'abcd 2345'])->assertOk();
    }

    private function env(string $key): ?string
    {
        return (new EnvFile($this->envPath))->get($key);
    }

    public function test_introuvable_hors_installation(): void
    {
        $this->get('/install')->assertNotFound();
        $this->postJson('/install/terminer')->assertNotFound();
        $this->get('/login')->assertOk();
    }

    public function test_tout_mene_a_l_assistant_et_le_code_est_exige(): void
    {
        $this->startInstall();

        $this->get('/login')->assertRedirect('/install');
        $this->get('/install')->assertOk()->assertInertia(fn ($p) => $p->component('Install/Wizard', false)->where('unlocked', false));
        $this->postJson('/install/site', [])->assertForbidden();
        $this->postJson('/install/acces', ['token' => 'XXXX0000'])->assertUnprocessable();
        $this->postJson('/install/acces', ['token' => 'abcd-2345'])->assertOk()->assertJsonPath('state.site.url', 'http://192.168.1.20:8080');
        $this->getJson('/install/verifications')->assertOk()->assertJsonStructure(['checks' => [['label', 'status', 'detail']], 'blocking']);

        // Fini : plus d'assistant, plus de redirection.
        file_put_contents($this->dir.'/finished.env', "PROFILES=\n");
        $this->get('/install')->assertNotFound();
        $this->get('/login')->assertOk();
    }

    public function test_site_avec_domaine_et_https(): void
    {
        $this->unlock();

        $this->postJson('/install/site', [
            'name' => 'Les souvenirs des Martin', 'access' => 'domain', 'domain' => 'Photos.Martin.fr',
            'timezone' => 'Europe/Paris', 'mode' => 'family',
        ])->assertOk()->assertJsonPath('state.site.url', 'https://photos.martin.fr');

        $this->assertSame('Les souvenirs des Martin', $this->env('APP_NAME'));
        $this->assertSame('https://photos.martin.fr', $this->env('APP_URL'));
        $this->assertSame('true', $this->env('SESSION_SECURE_COOKIE'));
        // Stockage intégré : les adresses présignées suivent l'adresse du site.
        $this->assertSame('https://photos.martin.fr', $this->env('SCALEWAY_PUBLIC_URL'));

        $this->postJson('/install/site', ['name' => 'X', 'access' => 'domain', 'domain' => 'pas un domaine', 'timezone' => 'Europe/Paris', 'mode' => 'family'])
            ->assertUnprocessable()->assertJsonValidationErrors('domain');
    }

    public function test_options_notifications_seulement_en_https(): void
    {
        $this->unlock();

        $this->postJson('/install/options', ['faces' => true, 'push' => true])->assertOk()
            ->assertJsonPath('state.options.push', false);
        $this->assertSame('insightface', $this->env('FACES_ENGINE'));
        $this->assertSame('', (string) $this->env('VAPID_PUBLIC_KEY'));

        (new EnvFile($this->envPath))->set(['APP_URL' => 'https://photos.martin.fr']);
        $this->postJson('/install/options', ['faces' => false, 'push' => true])->assertOk()
            ->assertJsonPath('state.options.push', true);
        $this->assertSame('faceapi', $this->env('FACES_ENGINE'));
        $this->assertNotEmpty($this->env('VAPID_PUBLIC_KEY'));
        $this->assertNotEmpty($this->env('VAPID_PRIVATE_KEY'));
    }

    public function test_installation_complete(): void
    {
        $this->unlock();
        $this->postJson('/install/site', ['name' => 'Souvenirs', 'access' => 'domain', 'domain' => 'photos.martin.fr', 'timezone' => 'Europe/Paris', 'mode' => 'family'])->assertOk();
        $this->postJson('/install/emails', ['mode' => 'log'])->assertOk();
        $this->postJson('/install/sauvegardes', ['mode' => 'none'])->assertOk();
        $this->postJson('/install/options', ['faces' => true])->assertOk();
        Installer::markStep('storage', ['storage' => 'local']);

        $this->postJson('/install/terminer')->assertUnprocessable();

        $this->postJson('/install/compte', [
            'first_name' => 'Camille', 'last_name' => 'Martin', 'email' => 'Camille@Example.org',
            'password' => 'motdepasse-solide', 'password_confirmation' => 'autre-chose', 'circle' => 'Famille Martin',
        ])->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->postJson('/install/compte', [
            'first_name' => 'Camille', 'last_name' => 'Martin', 'email' => 'Camille@Example.org',
            'password' => 'motdepasse-solide', 'password_confirmation' => 'motdepasse-solide', 'circle' => 'Famille Martin',
        ])->assertOk();

        $this->postJson('/install/terminer')->assertOk()
            ->assertJsonPath('url', 'https://photos.martin.fr')
            ->assertJsonPath('profiles', ['local-storage', 'faces', 'https']);

        $admin = User::where('email', 'camille@example.org')->firstOrFail();
        $this->assertTrue($admin->isAdmin());
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('motdepasse-solide', $admin->password));
        $this->assertSame('Camille Martin', $admin->person->name);
        $circle = Household::findOrFail($admin->current_household_id);
        $this->assertSame('Famille Martin', $circle->name);
        $this->assertSame(Household::KIND_FAMILY, $circle->kind);
        $this->assertTrue($circle->members()->whereKey($admin->id)->exists());
        // Plusieurs familles par installation : l'admin est responsable de la
        // première, et sa fiche y est rattachée (sinon invisible pour lui).
        $this->assertTrue($circle->isManager($admin));
        $this->assertTrue($circle->people()->whereKey($admin->person_id)->exists());
        $this->assertTrue(\App\Models\Person::visibleTo($admin)->whereKey($admin->person_id)->exists());

        $finished = file_get_contents($this->dir.'/finished.env');
        $this->assertStringContainsString('PROFILES=local-storage,faces,https', $finished);
        $this->assertStringContainsString('DOMAIN=photos.martin.fr', $finished);
        $this->assertStringContainsString('BIND=127.0.0.1', $finished);
        // Le mot de passe (haché) ne reste pas dans l'état.
        $this->assertNull(Installer::state()['account']['password'] ?? null);
        $this->get('/install')->assertNotFound();
    }

    public function test_sans_domaine_l_adresse_d_ecoute_n_est_jamais_elargie(): void
    {
        Installer::finish(['local-storage'], '', '');
        $this->assertStringContainsString("BIND=\n", file_get_contents($this->dir.'/finished.env'));

        Installer::finish(['local-storage', 'rm -rf /'], 'evil;domain', '0.0.0.0');
        $finished = file_get_contents($this->dir.'/finished.env');
        $this->assertStringContainsString("PROFILES=local-storage\n", $finished);
        $this->assertStringContainsString("DOMAIN=\n", $finished);
        $this->assertStringContainsString("BIND=\n", $finished);
    }

    public function test_ecriture_du_env_relue_a_l_identique(): void
    {
        $values = [
            'A_SIMPLE' => 'abc-123_./:@+,=',
            'A_SPACES' => 'Les souvenirs des Martin',
            'A_DOLLAR' => 'pa$$word ${APP_NAME}',
            'A_QUOTES' => "l'été \"chaud\" \$x \\n",
            'A_HASH' => 'mot#de#passe',
            'A_EMPTY' => '',
        ];
        (new EnvFile($this->envPath))->set($values);
        $parsed = Dotenv::parse(file_get_contents($this->envPath));

        foreach ($values as $key => $value) {
            $this->assertSame($value, $parsed[$key], $key);
            $this->assertSame($value, (new EnvFile($this->envPath))->get($key), $key);
        }
        // Commentaires et autres clés conservés.
        $this->assertStringContainsString('# --- Application', file_get_contents($this->envPath));
        $this->assertSame('pgsql', $parsed['DB_CONNECTION']);
    }

    public function test_adresses_presignees_sur_l_adresse_publique(): void
    {
        config(['filesystems.disks.scaleway' => [
            'driver' => 's3', 'key' => 'k', 'secret' => 's', 'region' => 'garage', 'bucket' => 'memorylane-files',
            'endpoint' => 'http://garage:3900', 'use_path_style_endpoint' => true,
            'temporary_url' => 'https://photos.martin.fr',
        ]]);
        $s3 = (new S3Service)->setDisk('scaleway');

        $url = $s3->presignUploadPart('media/videos/a.mp4', 'u1', 1);
        $this->assertStringStartsWith('https://photos.martin.fr/memorylane-files/media/videos/a.mp4?', $url);
        $this->assertStringContainsString('X-Amz-Signature=', $url);

        config(['filesystems.disks.scaleway.temporary_url' => null]);
        $this->assertStringStartsWith('http://garage:3900/memorylane-files/', (new S3Service)->setDisk('scaleway')->presignUploadPart('k', 'u', 1));
    }

    public function test_assistant_en_anglais_et_langue_du_site(): void
    {
        $this->unlock();
        // Bascule de langue permise pendant l'installation.
        $this->postJson('/locale', ['locale' => 'en'])->assertNoContent();
        $this->getJson('/install/verifications')->assertOk()->assertJsonPath('checks.1.label', 'PHP extensions');

        $this->postJson('/install/site', ['name' => 'Memories', 'access' => 'lan', 'timezone' => 'Europe/London', 'mode' => 'family'])->assertOk();
        $this->postJson('/install/emails', ['mode' => 'log'])->assertOk();
        $this->postJson('/install/sauvegardes', ['mode' => 'none'])->assertOk();
        $this->postJson('/install/options', [])->assertOk();
        Installer::markStep('storage', ['storage' => 'local']);
        $this->postJson('/install/compte', [
            'first_name' => 'Sam', 'last_name' => 'Smith', 'email' => 'sam@example.org',
            'password' => 'a-strong-password', 'password_confirmation' => 'a-strong-password', 'circle' => 'The Smith family',
        ])->assertOk();
        $this->postJson('/install/terminer')->assertOk();

        // Installé en anglais : le site est en anglais par défaut.
        $this->assertSame('en', \App\Support\SiteSettings::locale());
    }
}
