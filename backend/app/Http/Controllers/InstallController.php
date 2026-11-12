<?php

namespace App\Http\Controllers;

use App\Models\Household;
use App\Models\Person;
use App\Models\User;
use App\Support\Install\EnvFile;
use App\Support\Install\Installer;
use Aws\S3\S3Client;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Inertia\Inertia;

/**
 * Assistant d'installation (/install), « à la WordPress » : chaque étape
 * explique à quoi elle sert, teste ce qui peut l'être (stockage, e-mails,
 * sauvegardes) et écrit le résultat dans backend/.env. La dernière crée la
 * base et le compte administrateur, puis passe la main à install.sh, qui
 * démarre les services choisis.
 *
 * N'existe que pendant une installation (cf. Installer::pending) ; protégé
 * par le code affiché dans le terminal d'install.sh.
 */
class InstallController extends Controller
{
    /** Étapes à franchir avant de pouvoir installer. */
    private const STEPS = ['site', 'storage', 'mail', 'backup', 'options', 'account'];

    public function __construct(private EnvFile $env = new EnvFile) {}

    public function index(Request $request)
    {
        $unlocked = (bool) $request->session()->get('install_unlocked');

        return Inertia::render('Install/Wizard', [
            'unlocked' => $unlocked,
            'state' => $unlocked ? $this->publicState($request) : null,
        ]);
    }

    public function unlock(Request $request): JsonResponse
    {
        $request->validate(['token' => 'required|string|max:20']);
        if (! Installer::checkToken($request->input('token'))) {
            return response()->json(['errors' => ['token' => [__('Ce code ne correspond pas à celui du terminal.')]]], 422);
        }
        $request->session()->put('install_unlocked', true);

        return response()->json(['state' => $this->publicState($request)]);
    }

    // ---- 1. Vérifications ------------------------------------------------------

    public function checks(): JsonResponse
    {
        $checks = [];
        $add = function (string $label, string $status, string $detail = '', string $help = '') use (&$checks) {
            $checks[] = compact('label', 'status', 'detail', 'help');
        };

        $add('PHP', version_compare(PHP_VERSION, '8.3.0', '>=') ? 'ok' : 'error', PHP_VERSION);
        $missing = array_filter(['pdo_pgsql', 'redis', 'imagick', 'gd', 'exif', 'intl', 'zip', 'bcmath', 'pcntl'], fn ($e) => ! extension_loaded($e));
        $add(__('Extensions PHP'), $missing ? 'error' : 'ok', $missing ? __('Manquantes : :list', ['list' => implode(', ', $missing)]) : __('Toutes présentes'));

        try {
            $version = DB::selectOne('select version() as v')->v;
            $add(__('Base de données'), 'ok', Str::before($version, ' on '));
        } catch (\Throwable $e) {
            $add(__('Base de données'), 'error', __('Injoignable'), __('Voir : :cmd', ['cmd' => 'docker compose logs postgres']));
        }
        try {
            Redis::connection()->ping();
            $add(__('Cache et files d\'attente (Redis)'), 'ok', __('Connecté'));
        } catch (\Throwable $e) {
            $add(__('Cache et files d\'attente (Redis)'), 'error', __('Injoignable'), __('Voir : :cmd', ['cmd' => 'docker compose logs redis']));
        }
        try {
            $ok = Http::timeout(3)->get(rtrim(config('scout.meilisearch.host'), '/').'/health')->successful();
            $add(__('Moteur de recherche (Meilisearch)'), $ok ? 'ok' : 'error', $ok ? __('Prêt') : __('Ne répond pas'));
        } catch (\Throwable $e) {
            $add(__('Moteur de recherche (Meilisearch)'), 'error', __('Injoignable'), __('Voir : :cmd', ['cmd' => 'docker compose logs meilisearch']));
        }

        $heic = class_exists(\Imagick::class) && \Imagick::queryFormats('HEIC') !== [];
        $add(__('Photos d\'iPhone (HEIC)'), $heic ? 'ok' : 'warn', $heic ? __('Lues et converties') : __('Non prises en charge par ImageMagick'));
        $ffmpeg = trim((string) @shell_exec('command -v ffmpeg'));
        $add(__('Vidéos (ffmpeg)'), $ffmpeg ? 'ok' : 'error', $ffmpeg ? __('Installé') : __('Absent'));

        $writable = is_writable(base_path('.env')) && is_writable(storage_path()) && is_writable(base_path('bootstrap/cache'));
        $add(__('Droits d\'écriture'), $writable ? 'ok' : 'error', $writable ? __('Configuration et dossiers de travail') : __('backend/.env ou storage/ non modifiable'), __('Les fichiers doivent appartenir à l\'utilisateur qui a lancé install.sh.'));

        $memKb = (int) (preg_match('/MemTotal:\s+(\d+)/', (string) @file_get_contents('/proc/meminfo'), $m) ? $m[1] : 0);
        $memGb = round($memKb / 1024 / 1024, 1);
        $add(__('Mémoire du serveur'), $memGb >= 6 ? 'ok' : 'warn', __(':n Go', ['n' => $memGb]), $memGb >= 6 ? '' : __('La reconnaissance des visages demande environ 3 Go de plus : à éviter sous 6 Go.'));
        $cpus = (int) trim((string) @shell_exec('nproc'));
        $add(__('Processeurs'), $cpus >= 2 ? 'ok' : 'warn', $cpus ? trans_choice(':count cœur|:count cœurs', $cpus) : __('Inconnu'));
        $freeGb = (int) round(@disk_free_space(base_path()) / 1024 ** 3);
        $add(__('Espace disque libre'), $freeGb >= 20 ? 'ok' : 'warn', __(':n Go', ['n' => $freeGb]), __('Avec le stockage intégré, vos photos et vidéos sont sur ce disque.'));

        return response()->json(['checks' => $checks, 'blocking' => collect($checks)->contains('status', 'error')]);
    }

    // ---- 2. Votre site -----------------------------------------------------------

    public function site(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:60',
            'access' => 'required|in:lan,domain,proxy',
            'domain' => 'required_if:access,domain|nullable|string|max:190|regex:/^(?=.{1,190}$)([a-z0-9]([a-z0-9-]*[a-z0-9])?\.)+[a-z]{2,}$/i',
            'url' => 'required_if:access,proxy|nullable|url:https,http|max:190',
            'timezone' => 'required|timezone',
            'mode' => 'required|in:family,friends',
        ], [
            'domain.regex' => __('Ce nom de domaine n\'a pas l\'air valide (exemple : photos.mafamille.fr).'),
        ]);

        $url = match ($data['access']) {
            'lan' => rtrim($request->getSchemeAndHttpHost(), '/'),
            'domain' => 'https://'.strtolower($data['domain']),
            'proxy' => rtrim($data['url'], '/'),
        };
        $https = str_starts_with($url, 'https://');

        $values = [
            'APP_NAME' => $data['name'],
            'APP_URL' => $url,
            'APP_TIMEZONE' => $data['timezone'],
            'MEMORYLANE_MODE' => $data['mode'],
            'SESSION_SECURE_COOKIE' => $https,
        ];
        if ($this->env->get('SCALEWAY_ENDPOINT') === 'http://garage:3900') {
            $values['SCALEWAY_PUBLIC_URL'] = $url;
        }
        $this->env->set($values);

        Installer::markStep('site', [
            'access' => $data['access'],
            'domain' => $data['access'] === 'domain' ? strtolower($data['domain']) : '',
        ]);

        return response()->json(['state' => $this->publicState($request)]);
    }

    // ---- 3. Photos et vidéos -------------------------------------------------------

    public function storage(Request $request): JsonResponse
    {
        $data = $request->validate([
            'mode' => 'required|in:local,s3',
            'endpoint' => 'required_if:mode,s3|nullable|url:https,http',
            'region' => 'required_if:mode,s3|nullable|string|max:60',
            'bucket' => 'required_if:mode,s3|nullable|string|max:63',
            'key' => 'required_if:mode,s3|nullable|string|max:200',
            'secret' => 'nullable|string|max:200',
            'test_only' => 'boolean',
        ]);

        $appUrl = $this->env->get('APP_URL');
        if ($data['mode'] === 'local') {
            $config = [
                'endpoint' => 'http://garage:3900', 'region' => 'garage', 'bucket' => 'memorylane-files',
                'key' => $this->env->get('GARAGE_ACCESS_KEY'), 'secret' => $this->env->get('GARAGE_SECRET_KEY'),
            ];
        } else {
            $config = [
                'endpoint' => rtrim($data['endpoint'], '/'), 'region' => $data['region'], 'bucket' => $data['bucket'],
                'key' => $data['key'],
                // Secret laissé vide : on garde celui déjà enregistré.
                'secret' => $data['secret'] ?: ($this->env->get('SCALEWAY_ENDPOINT') === rtrim($data['endpoint'], '/') ? $this->env->get('SCALEWAY_SECRET_KEY') : null),
            ];
            if (! $config['secret']) {
                return $this->fail('secret', __('La clé secrète est nécessaire.'));
            }
        }

        $report = $this->testS3($config, $data['mode'] === 'local' ? 'http://nginx' : null);
        if ($report['error']) {
            return $this->fail('test', $report['error'], $report);
        }
        // Stockage externe : le navigateur y envoie les photos directement,
        // le stockage doit l'autoriser pour l'adresse du site (CORS).
        if ($data['mode'] === 's3') {
            try {
                $this->s3($config)->putBucketCors(['Bucket' => $config['bucket'], 'CORSConfiguration' => ['CORSRules' => [[
                    'AllowedOrigins' => [$appUrl],
                    'AllowedMethods' => ['GET', 'PUT', 'POST', 'HEAD'],
                    'AllowedHeaders' => ['*'],
                    'ExposeHeaders' => ['ETag'],
                    'MaxAgeSeconds' => 3600,
                ]]]]);
                $report['steps'][] = __('Envois directs depuis le navigateur autorisés pour :url', ['url' => $appUrl]);
            } catch (\Throwable $e) {
                $report['warning'] = __('Impossible de régler le CORS du bucket (:error). Les envois depuis le navigateur seront refusés tant qu\'il n\'autorise pas :url (méthodes GET, PUT, POST, en-tête ETag exposé).', ['error' => Str::limit($e->getMessage(), 120), 'url' => $appUrl]);
            }
        }
        if ($request->boolean('test_only')) {
            return response()->json(['report' => $report]);
        }

        $this->env->set([
            'FILESYSTEM_DISK' => 'scaleway',
            'SCALEWAY_ENDPOINT' => $config['endpoint'],
            'SCALEWAY_REGION' => $config['region'],
            'SCALEWAY_BUCKET' => $config['bucket'],
            'SCALEWAY_ACCESS_KEY' => $config['key'],
            'SCALEWAY_SECRET_KEY' => $config['secret'],
            'SCALEWAY_PUBLIC_URL' => $data['mode'] === 'local' ? $appUrl : '',
            'BACKUP_SRC_PROVIDER' => str_contains($config['endpoint'], 'scw.cloud') ? 'Scaleway' : 'Other',
        ]);
        Installer::markStep('storage', ['storage' => $data['mode']]);

        return response()->json(['report' => $report, 'state' => $this->publicState($request)]);
    }

    // ---- 4. E-mails -------------------------------------------------------------

    public function mail(Request $request): JsonResponse
    {
        $data = $request->validate([
            'mode' => 'required|in:log,smtp',
            'host' => 'required_if:mode,smtp|nullable|string|max:190',
            'port' => 'required_if:mode,smtp|nullable|integer|between:1,65535',
            'username' => 'nullable|string|max:190',
            'password' => 'nullable|string|max:300',
            'from_address' => 'required_if:mode,smtp|nullable|email',
            'from_name' => 'nullable|string|max:80',
            'test_to' => 'nullable|email',
            'test_only' => 'boolean',
        ]);

        if ($data['mode'] === 'log') {
            $this->env->set(['MAIL_MAILER' => 'log']);
            Installer::markStep('mail', ['mail' => 'log']);

            return response()->json(['state' => $this->publicState($request)]);
        }

        $password = $data['password'] ?: $this->env->get('MAIL_PASSWORD');
        if (! empty($data['test_to'])) {
            try {
                Mail::build([
                    'transport' => 'smtp',
                    'host' => $data['host'],
                    'port' => (int) $data['port'],
                    'username' => $data['username'] ?: null,
                    'password' => $password ?: null,
                    'timeout' => 15,
                ])->raw(
                    __("Bonjour,\n\nLes e-mails de MemoryLane fonctionnent : invitations, liens de connexion et nouvelles de la famille arriveront bien.\n\nÀ bientôt !"),
                    fn ($m) => $m->to($data['test_to'])
                        ->from($data['from_address'], $data['from_name'] ?: 'MemoryLane')
                        ->subject(__('MemoryLane : e-mail de test'))
                );
            } catch (\Throwable $e) {
                return $this->fail('test', __('L\'envoi a échoué : :error', ['error' => Str::limit($e->getMessage(), 300)]));
            }
            if ($request->boolean('test_only')) {
                return response()->json(['sent' => true]);
            }
        }

        $this->env->set([
            'MAIL_MAILER' => 'smtp',
            'MAIL_HOST' => $data['host'],
            'MAIL_PORT' => (int) $data['port'],
            'MAIL_USERNAME' => $data['username'] ?? '',
            'MAIL_PASSWORD' => $password ?? '',
            'MAIL_FROM_ADDRESS' => $data['from_address'],
            'MAIL_FROM_NAME' => $data['from_name'] ?: '${APP_NAME}',
        ]);
        Installer::markStep('mail', ['mail' => 'smtp']);

        return response()->json(['state' => $this->publicState($request)]);
    }

    // ---- 5. Sauvegardes ----------------------------------------------------------

    public function backup(Request $request): JsonResponse
    {
        $data = $request->validate([
            'mode' => 'required|in:none,s3',
            'endpoint' => 'required_if:mode,s3|nullable|url:https,http',
            'region' => 'required_if:mode,s3|nullable|string|max:60',
            'bucket' => 'required_if:mode,s3|nullable|string|max:63',
            'key' => 'required_if:mode,s3|nullable|string|max:200',
            'secret' => 'nullable|string|max:200',
            'test_only' => 'boolean',
        ]);

        if ($data['mode'] === 'none') {
            Installer::markStep('backup', ['backup' => 'none']);

            return response()->json(['state' => $this->publicState($request)]);
        }

        $config = [
            'endpoint' => rtrim($data['endpoint'], '/'), 'region' => $data['region'], 'bucket' => $data['bucket'],
            'key' => $data['key'], 'secret' => $data['secret'] ?: $this->env->get('BACKUP_S3_SECRET_KEY'),
        ];
        if (! $config['secret']) {
            return $this->fail('secret', __('La clé secrète est nécessaire.'));
        }
        $report = $this->testS3($config, null, presign: false);
        if ($report['error']) {
            return $this->fail('test', $report['error'], $report);
        }
        if ($request->boolean('test_only')) {
            return response()->json(['report' => $report]);
        }

        // Phrases de chiffrement : générées une fois, à garder hors du serveur.
        $password = $this->env->get('BACKUP_CRYPT_PASSWORD') ?: Str::password(32, symbols: false);
        $salt = $this->env->get('BACKUP_CRYPT_SALT') ?: Str::password(32, symbols: false);
        $this->env->set([
            'BACKUP_S3_ENDPOINT' => $config['endpoint'],
            'BACKUP_S3_REGION' => $config['region'],
            'BACKUP_S3_BUCKET' => $config['bucket'],
            'BACKUP_S3_ACCESS_KEY' => $config['key'],
            'BACKUP_S3_SECRET_KEY' => $config['secret'],
            'BACKUP_CRYPT_PASSWORD' => $password,
            'BACKUP_CRYPT_SALT' => $salt,
        ]);
        Installer::markStep('backup', ['backup' => 's3']);

        return response()->json([
            'report' => $report,
            'secrets' => ['password' => $password, 'salt' => $salt],
            'state' => $this->publicState($request),
        ]);
    }

    // ---- 6. Options ----------------------------------------------------------------

    public function options(Request $request): JsonResponse
    {
        $data = $request->validate([
            'faces' => 'boolean',
            'push' => 'boolean',
            'google_client_id' => 'nullable|string|max:200',
            'google_client_secret' => 'nullable|string|max:200',
            'google_drive_key' => 'nullable|string|max:200',
            'healthcheck_url' => 'nullable|url:https,http|max:300',
        ]);

        $values = [
            'FACES_ENGINE' => ! empty($data['faces']) ? 'insightface' : 'faceapi',
            'GOOGLE_PHOTOS_CLIENT_ID' => $data['google_client_id'] ?? '',
            'GOOGLE_DRIVE_API_KEY' => $data['google_drive_key'] ?? '',
            'HEALTHCHECK_PING_URL' => $data['healthcheck_url'] ?? '',
        ];
        if (! empty($data['google_client_secret'])) {
            $values['GOOGLE_PHOTOS_CLIENT_SECRET'] = $data['google_client_secret'];
        } elseif (empty($data['google_client_id'])) {
            $values['GOOGLE_PHOTOS_CLIENT_SECRET'] = '';
        }

        // Notifications : clés VAPID créées une fois (les changer désabonne tout le monde).
        $https = str_starts_with((string) $this->env->get('APP_URL'), 'https://');
        if (! empty($data['push']) && $https) {
            if (! $this->env->get('VAPID_PUBLIC_KEY')) {
                $keys = \Minishlink\WebPush\VAPID::createVapidKeys();
                $values['VAPID_PUBLIC_KEY'] = $keys['publicKey'];
                $values['VAPID_PRIVATE_KEY'] = $keys['privateKey'];
            }
            $values['VAPID_SUBJECT'] = $this->env->get('APP_URL');
        } else {
            $values += ['VAPID_PUBLIC_KEY' => '', 'VAPID_PRIVATE_KEY' => '', 'VAPID_SUBJECT' => ''];
        }

        $this->env->set($values);
        Installer::markStep('options', ['faces' => ! empty($data['faces']), 'push' => ! empty($data['push']) && $https]);

        return response()->json(['state' => $this->publicState($request)]);
    }

    // ---- 7. Compte administrateur -------------------------------------------------

    public function account(Request $request): JsonResponse
    {
        $data = $request->validate([
            'first_name' => 'required|string|max:80',
            'last_name' => 'required|string|max:80',
            'email' => 'required|email|max:190',
            'password' => 'required|string|min:10|max:200|confirmed',
            'circle' => 'required|string|max:120',
        ], [
            'password.min' => __('Le mot de passe doit faire au moins 10 caractères.'),
            'password.confirmed' => __('Les deux mots de passe ne sont pas identiques.'),
        ]);

        // Rien n'est encore en base : le compte est créé à l'installation.
        Installer::markStep('account', ['account' => [
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'],
            'email' => strtolower($data['email']),
            'password' => Hash::make($data['password']),
            'circle' => $data['circle'],
        ]]);
        if ($this->env->get('MAIL_MAILER') === 'log' || $this->env->get('MAIL_FROM_ADDRESS') === 'memorylane@example.com') {
            $this->env->set(['MAIL_FROM_ADDRESS' => strtolower($data['email'])]);
        }

        return response()->json(['state' => $this->publicState($request)]);
    }

    // ---- 8. Installation --------------------------------------------------------------

    public function finish(Request $request): JsonResponse
    {
        $state = Installer::state();
        $missing = array_values(array_diff(self::STEPS, array_keys($state['done'] ?? [])));
        if ($missing) {
            return response()->json(['message' => __('Étapes à compléter d\'abord : :steps', ['steps' => implode(', ', $missing)])], 422);
        }
        @set_time_limit(600);

        $log = [];
        try {
            Artisan::call('migrate', ['--force' => true]);
            $log[] = __('Base de données créée');

            $account = $state['account'];
            $admin = DB::transaction(function () use ($account, $state) {
                $user = User::where('email', $account['email'])->first() ?? new User;
                $user->forceFill([
                    'name' => trim($account['first_name'].' '.$account['last_name']),
                    'email' => $account['email'],
                    'password' => $account['password'],
                    'role' => 'admin',
                    'email_verified_at' => now(),
                ])->save();

                if (! $user->person_id) {
                    $person = new Person;
                    $person->forceFill([
                        'first_name' => $account['first_name'],
                        'last_name' => $account['last_name'],
                        'user_id' => $user->id,
                        'in_family_directory' => true,
                    ])->save();
                    $user->forceFill(['person_id' => $person->id])->save();
                }

                if (! $user->current_household_id) {
                    $kind = $this->env->get('MEMORYLANE_MODE') === 'friends' ? Household::KIND_FRIENDS : Household::KIND_FAMILY;
                    $circle = Household::create([
                        'name' => $account['circle'],
                        'kind' => $kind,
                        'invite_policy' => Household::defaultInvitePolicy($kind),
                        'created_by' => $user->id,
                    ]);
                    // L'administrateur en est le premier responsable (comme tout créateur de cercle).
                    $circle->addMember($user, $user, Household::ROLE_MANAGER);
                    $user->forceFill(['current_household_id' => $circle->id])->save();
                }

                return $user;
            });
            $log[] = __('Compte administrateur : :email', ['email' => $admin->email]);

            // Langue de l'assistant = langue par défaut du site.
            \App\Support\SiteSettings::set('locale', app()->getLocale());

            // Index de recherche : réglés par install.sh (commandes Scout
            // disponibles en ligne de commande seulement).
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['message' => __('L\'installation a échoué : :error', ['error' => Str::limit($e->getMessage(), 400)]), 'log' => $log], 500);
        }

        $profiles = array_filter([
            ($state['storage'] ?? 'local') === 'local' ? 'local-storage' : null,
            ! empty($state['faces']) ? 'faces' : null,
            ($state['backup'] ?? 'none') === 's3' ? 'backup' : null,
            ($state['access'] ?? 'lan') === 'domain' ? 'https' : null,
        ]);
        Installer::remember(['account' => ['password' => null], 'installed_at' => now()->toIso8601String()]);
        Installer::finish($profiles, $state['domain'] ?? '', ($state['access'] ?? 'lan') === 'domain' ? '127.0.0.1' : '');
        $request->session()->forget('install_unlocked');

        return response()->json([
            'log' => $log,
            'url' => $this->env->get('APP_URL'),
            'email' => $admin->email,
            'profiles' => array_values($profiles),
        ]);
    }

    // ---- Outils ------------------------------------------------------------------------

    /** Ce que la page a besoin de savoir (aucun secret). */
    private function publicState(Request $request): array
    {
        $state = Installer::state();
        $e = fn (string $k) => $this->env->get($k);
        $local = $e('SCALEWAY_ENDPOINT') === 'http://garage:3900';

        return [
            'done' => array_keys($state['done'] ?? []),
            'suggestedUrl' => rtrim($request->getSchemeAndHttpHost(), '/'),
            'site' => [
                'name' => $e('APP_NAME'),
                'url' => $e('APP_URL'),
                'access' => $state['access'] ?? 'lan',
                'domain' => $state['domain'] ?? '',
                'timezone' => $e('APP_TIMEZONE') ?: 'Europe/Paris',
                'mode' => $e('MEMORYLANE_MODE') ?: 'family',
            ],
            'storage' => [
                'mode' => $local ? 'local' : 's3',
                'endpoint' => $local ? '' : $e('SCALEWAY_ENDPOINT'),
                'region' => $local ? '' : $e('SCALEWAY_REGION'),
                'bucket' => $local ? '' : $e('SCALEWAY_BUCKET'),
                'key' => $local ? '' : $e('SCALEWAY_ACCESS_KEY'),
                'hasSecret' => ! $local && (bool) $e('SCALEWAY_SECRET_KEY'),
                'localReady' => (bool) $e('GARAGE_ACCESS_KEY'),
            ],
            'mail' => [
                'mode' => $e('MAIL_MAILER') === 'smtp' ? 'smtp' : 'log',
                'host' => $e('MAIL_HOST'),
                'port' => (int) ($e('MAIL_PORT') ?: 587),
                'username' => $e('MAIL_USERNAME'),
                'hasPassword' => (bool) $e('MAIL_PASSWORD'),
                'from_address' => $e('MAIL_FROM_ADDRESS') === 'memorylane@example.com' ? '' : $e('MAIL_FROM_ADDRESS'),
                'from_name' => str_starts_with((string) $e('MAIL_FROM_NAME'), '${') ? '' : $e('MAIL_FROM_NAME'),
            ],
            'backup' => [
                'mode' => $state['backup'] ?? 'none',
                'endpoint' => $e('BACKUP_S3_ENDPOINT'),
                'region' => $e('BACKUP_S3_REGION'),
                'bucket' => $e('BACKUP_S3_BUCKET'),
                'key' => $e('BACKUP_S3_ACCESS_KEY'),
                'hasSecret' => (bool) $e('BACKUP_S3_SECRET_KEY'),
            ],
            'options' => [
                'faces' => (bool) ($state['faces'] ?? false),
                'push' => (bool) ($state['push'] ?? false),
                'google_client_id' => $e('GOOGLE_PHOTOS_CLIENT_ID'),
                'hasGoogleSecret' => (bool) $e('GOOGLE_PHOTOS_CLIENT_SECRET'),
                'google_drive_key' => $e('GOOGLE_DRIVE_API_KEY'),
                'healthcheck_url' => $e('HEALTHCHECK_PING_URL'),
            ],
            'account' => isset($state['account']['email']) ? [
                'first_name' => $state['account']['first_name'],
                'last_name' => $state['account']['last_name'],
                'email' => $state['account']['email'],
                'circle' => $state['account']['circle'],
            ] : null,
        ];
    }

    private function s3(array $c): S3Client
    {
        return new S3Client([
            'version' => 'latest',
            'region' => $c['region'] ?: 'us-east-1',
            'endpoint' => $c['endpoint'],
            'use_path_style_endpoint' => true,
            'credentials' => ['key' => $c['key'], 'secret' => $c['secret']],
            'http' => ['connect_timeout' => 5, 'timeout' => 20],
        ]);
    }

    /**
     * Écrit, relit (directement puis par une adresse présignée, comme le
     * navigateur) et efface un petit fichier de test.
     *
     * @param  string|null  $publicBase  stockage intégré : relit en passant par nginx
     */
    private function testS3(array $c, ?string $publicBase, bool $presign = true): array
    {
        $report = ['steps' => [], 'error' => null, 'warning' => null];
        $key = 'memorylane-install-check/'.Str::uuid().'.txt';
        $body = 'MemoryLane '.now()->toIso8601String();
        try {
            $client = $this->s3($c);
            $client->putObject(['Bucket' => $c['bucket'], 'Key' => $key, 'Body' => $body, 'ContentType' => 'text/plain']);
            $report['steps'][] = __('Écriture d\'un fichier de test');
            $read = (string) $client->getObject(['Bucket' => $c['bucket'], 'Key' => $key])['Body'];
            if ($read !== $body) {
                throw new \RuntimeException(__('Le fichier relu ne correspond pas.'));
            }
            $report['steps'][] = __('Relecture');
            if ($presign) {
                $url = (string) $client->createPresignedRequest($client->getCommand('GetObject', ['Bucket' => $c['bucket'], 'Key' => $key]), '+5 minutes')->getUri();
                if ($publicBase) {
                    $parts = parse_url($url);
                    $url = $publicBase.$parts['path'].'?'.$parts['query'];
                }
                $response = Http::timeout(10)->get($url);
                if (! $response->successful() || $response->body() !== $body) {
                    throw new \RuntimeException(__('Lien de lecture signé refusé (HTTP :status).', ['status' => $response->status()]));
                }
                $report['steps'][] = $publicBase ? __('Lecture par le site (comme le navigateur)') : __('Lecture par lien signé (comme le navigateur)');
            }
            $client->deleteObject(['Bucket' => $c['bucket'], 'Key' => $key]);
            $report['steps'][] = __('Suppression');
        } catch (\Aws\Exception\AwsException $e) {
            $report['error'] = match ($e->getAwsErrorCode()) {
                'NoSuchBucket' => __('Le bucket « :bucket » n\'existe pas : créez-le d\'abord chez votre fournisseur.', ['bucket' => $c['bucket']]),
                'InvalidAccessKeyId', 'SignatureDoesNotMatch' => __('Clés refusées : vérifiez la clé d\'accès et la clé secrète.'),
                'AccessDenied' => __('Accès refusé : cette clé n\'a pas les droits de lecture et d\'écriture sur le bucket.'),
                default => __('Erreur du stockage : :error', ['error' => Str::limit($e->getAwsErrorMessage() ?: $e->getMessage(), 200)]),
            };
        } catch (\Throwable $e) {
            $report['error'] = __('Connexion impossible : :error', ['error' => Str::limit($e->getMessage(), 200)]);
        }

        return $report;
    }

    private function fail(string $field, string $message, array $extra = []): JsonResponse
    {
        return response()->json(['message' => $message, 'errors' => [$field => [$message]]] + $extra, 422);
    }
}
