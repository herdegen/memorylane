<?php

namespace Tests\Feature;

use App\Http\Middleware\RestrictGuest;
use App\Http\Middleware\RestrictSimpleMode;
use App\Http\Middleware\RestrictTv;
use App\Models\TvDevice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Inventaire de TOUTES les routes : une route ajoutée demain doit être soit
 * déclarée publique ici (avec sa raison et sa protection : signature, jeton,
 * limitation de débit), soit derrière la connexion ET les trois restrictions
 * de comptes (invité, tablette, TV). Sinon ce test échoue et la nomme.
 */
class RouteInventoryTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Routes accessibles sans être connecté : uri => [raison, middleware exigé].
     * Le middleware exigé (nom court) garantit que la protection ne disparaît
     * pas en silence (ex. un lien signé qui ne le serait plus).
     */
    private const PUBLIC_ROUTES = [
        '/' => ['redirection vers l\'accueil ou la connexion', null],
        'login' => ['formulaire de connexion', 'RedirectIfAuthenticated'],
        'login/magic' => ['demande de lien de connexion (limitée dans AuthController)', 'RedirectIfAuthenticated'],
        'login/magic/{user}' => ['lien de connexion à usage unique', 'ValidateSignature'],
        'invite/{user}/{person}' => ['compte invité temporaire', 'ValidateSignature'],
        'locale' => ['choix de la langue avant connexion', 'ThrottleRequests'],
        'i18n/{locale}' => ['traductions de l\'interface', null],
        'bienvenue/{token}' => ['invitation (jeton secret)', 'ThrottleRequests'],
        'newsletter/unsubscribe/{user}' => ['désabonnement depuis la lettre', 'ValidateSignature'],
        'newsletter/resubscribe/{user}' => ['réabonnement depuis la lettre', 'ValidateSignature'],
        'tablette/{token}' => ['mode grand-parent (jeton secret)', 'ThrottleRequests'],
        'tv/application' => ['téléchargement de l\'appli TV', null],
        'apk' => ['raccourci vers l\'appli TV', null],
        'tv/ecran' => ['écran d\'appairage de la TV', null],
        'tv/ecran/code' => ['code d\'appairage de la TV', 'ThrottleRequests'],
        'albums/shared/{token}' => ['album partagé par lien secret', null],
        'albums/shared/{token}/media/{media}/file/{conversion?}' => ['fichier d\'un album partagé par lien', null],
    ];

    /**
     * Routes connectées volontairement accessibles aux comptes restreints
     * sans passer par leurs restrictions.
     */
    private const UNRESTRICTED_AUTH_ROUTES = [
        'logout' => 'tout compte doit pouvoir se déconnecter',
        'impersonate/leave' => 'l\'admin doit pouvoir revenir à son compte',
    ];

    /** Routes des briques tierces, protégées par elles-mêmes (Filament : voir plus bas). */
    private function isVendor(RoutingRoute $route): bool
    {
        $uri = $route->uri();
        $name = (string) $route->getName();

        return str_starts_with($name, 'livewire.') || str_starts_with($name, 'default-livewire.')
            || preg_match('#^(livewire|_inertia|_ignition|horizon|sanctum|storage|up$)#', $uri)
            || str_starts_with($name, 'filament.');
    }

    /** @return array<int,string> noms courts des middlewares effectifs */
    private function middlewareOf(RoutingRoute $route): array
    {
        return array_map(
            fn ($m) => is_string($m) ? class_basename(explode(':', $m)[0]) : 'Closure',
            app('router')->gatherRouteMiddleware($route)
        );
    }

    private function requiresLogin(array $middleware): bool
    {
        return in_array('Authenticate', $middleware, true);
    }

    /** @return iterable<RoutingRoute> */
    private function appRoutes(): iterable
    {
        foreach (Route::getRoutes() as $route) {
            if (! $this->isVendor($route)) {
                yield $route;
            }
        }
    }

    public function test_every_route_is_either_declared_public_or_behind_login_and_restrictions(): void
    {
        $problems = [];

        foreach ($this->appRoutes() as $route) {
            $uri = $route->uri();
            $mw = $this->middlewareOf($route);
            $label = implode('|', array_diff($route->methods(), ['HEAD'])) . " /{$uri}";

            if (! $this->requiresLogin($mw)) {
                if (! isset(self::PUBLIC_ROUTES[$uri])) {
                    $problems[] = "{$label} : accessible sans connexion, mais pas déclarée dans PUBLIC_ROUTES";
                } elseif (($needed = self::PUBLIC_ROUTES[$uri][1]) && ! in_array($needed, $mw, true)) {
                    $problems[] = "{$label} : route publique qui a perdu sa protection {$needed}";
                }

                continue;
            }

            if (isset(self::UNRESTRICTED_AUTH_ROUTES[$uri])) {
                continue;
            }

            $missing = array_diff(['RestrictGuest', 'RestrictSimpleMode', 'RestrictTv'], $mw);
            if ($missing) {
                $problems[] = "{$label} : connectée mais sans " . implode(', ', $missing);
            }
        }

        $this->assertSame([], $problems, "Routes à classer ou à protéger :\n" . implode("\n", $problems));
    }

    public function test_declared_exceptions_still_exist(): void
    {
        $uris = collect(Route::getRoutes()->getRoutes())->map->uri()->all();

        foreach (array_keys(self::PUBLIC_ROUTES + self::UNRESTRICTED_AUTH_ROUTES) as $uri) {
            $this->assertContains($uri, $uris, "Exception déclarée pour /{$uri}, qui n'existe plus : la retirer de la liste.");
        }
    }

    /**
     * Les listes de pages autorisées des comptes restreints ne visent que des
     * routes existantes (une route renommée y resterait sinon en silence).
     */
    public function test_restricted_account_allowlists_name_existing_routes(): void
    {
        foreach ([RestrictGuest::class, RestrictSimpleMode::class, RestrictTv::class] as $class) {
            $allowed = (new \ReflectionClass($class))->getConstant('ALLOWED_ROUTES');
            $this->assertIsArray($allowed, "{$class} n'a plus de ALLOWED_ROUTES");

            foreach ($allowed as $name) {
                $this->assertTrue(Route::has($name), class_basename($class) . " autorise « {$name} », route inexistante.");
            }
        }
    }

    /**
     * L'administration (Filament) exige la connexion sur toutes ses pages,
     * hors connexion et déconnexion.
     */
    public function test_admin_panel_routes_require_login(): void
    {
        $open = [];
        foreach (Route::getRoutes() as $route) {
            $name = (string) $route->getName();
            if (! str_starts_with($name, 'filament.admin.') || str_starts_with($name, 'filament.admin.auth.')) {
                continue;
            }
            if (! $this->requiresLogin($this->middlewareOf($route))) {
                $open[] = "/{$route->uri()} [{$name}]";
            }
        }

        $this->assertSame([], $open, "Pages d'admin accessibles sans connexion :\n" . implode("\n", $open));
    }

    /**
     * Vérification en conditions réelles : chaque page sans paramètre hors
     * liste autorisée renvoie l'invité, la tablette et la TV vers leur espace.
     */
    public function test_restricted_accounts_are_bounced_from_every_page_outside_their_allowlist(): void
    {
        $owner = User::factory()->create();
        $guest = User::factory()->create(['guest_expires_at' => now()->addDays(7)]);
        $tablet = User::factory()->create(['simple_mode' => true]);
        $tvDevice = TvDevice::create([
            'user_id' => $owner->id,
            'token_hash' => TvDevice::hashToken('jeton-test'),
            'confirmed_at' => now(),
        ]);

        $accounts = [
            'invité' => [$guest, [], RestrictGuest::class, fn ($r) => false, route('family-tree.index')],
            'tablette' => [$tablet, [], RestrictSimpleMode::class, fn ($r) => str_starts_with($r, 'simple.'), route('simple.home')],
            'TV' => [$owner, ['tv_device_id' => $tvDevice->id], RestrictTv::class, fn ($r) => str_starts_with($r, 'tv.screen.'), route('tv.screen.home')],
        ];

        $problems = [];
        $checked = 0;

        foreach ($this->appRoutes() as $route) {
            $name = (string) $route->getName();
            if (! in_array('GET', $route->methods(), true) || $route->parameterNames() !== []
                || ! $this->requiresLogin($this->middlewareOf($route))
                || isset(self::UNRESTRICTED_AUTH_ROUTES[$route->uri()])) {
                continue;
            }

            foreach ($accounts as $label => [$user, $session, $middleware, $ownSpace, $home]) {
                $allowed = (new \ReflectionClass($middleware))->getConstant('ALLOWED_ROUTES');
                if (in_array($name, $allowed, true) || $ownSpace($name)) {
                    continue;
                }

                $response = $this->actingAs($user)->withSession($session)->get('/' . ltrim($route->uri(), '/'));
                $checked++;

                if (! $response->isRedirect($home) && $response->getStatusCode() !== 403) {
                    $problems[] = "{$label} : GET /{$route->uri()} → {$response->getStatusCode()}";
                }
                $this->flushSession();
            }
        }

        $this->assertGreaterThan(50, $checked, 'Trop peu de pages vérifiées : le balayage ne trouve plus les routes.');
        $this->assertSame([], $problems, "Pages ouvertes à un compte restreint :\n" . implode("\n", $problems));
    }
}
