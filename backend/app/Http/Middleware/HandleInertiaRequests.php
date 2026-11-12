<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return array_merge(parent::share($request), [
            'auth' => [
                'user' => $request->user() ? [
                    'id' => $request->user()->id,
                    'name' => $request->user()->name,
                    'email' => $request->user()->email,
                    'role' => $request->user()->role,
                    'person_id' => $request->user()->person_id,
                    'simple_mode' => (bool) $request->user()->simple_mode,
                    'is_guest' => $request->user()->isGuest(),
                    // Départ de l'application programmé : date d'effet (bandeau d'annulation).
                    'departure_at' => $request->user()->departure_requested_at
                        ?->copy()->addDays(\App\Services\DepartureService::GRACE_DAYS)->toDateString(),
                    // Premiers pas déjà vus (mini-tuto d'envoi, visite guidée).
                    'onboarding' => (object) ($request->user()->preferences['onboarding'] ?? []),
                ] : null,
            ],
            // Notifications push : clé publique VAPID + état (profil, carte dashboard)
            // « Se connecter en tant que » : bandeau de retour au compte admin.
            'impersonating' => fn () => \App\Services\Impersonation::active() && $request->user()
                ? ['name' => $request->user()->name]
                : null,
            // Fête d'anniversaire du jour, à la première visite (une fois par
            // jour) ; pas en « se connecter en tant que » ni pour un invité.
            'birthdayGreeting' => fn () => $request->user()
                && ! $request->user()->isGuest()
                && ! \App\Services\Impersonation::active()
                    ? app(\App\Services\BirthdayGreeting::class)->pending($request->user())
                    : null,
            // Moteur de reconnaissance : 'insightface' = analyse côté serveur
            // (le navigateur ne charge plus face-api).
            'faces_engine' => config('memorylane.faces.engine'),
            'push' => fn () => $request->user() ? [
                'vapid_public_key' => config('webpush.vapid.public_key'),
                'preferences' => $request->user()->pushPreferences(),
                'has_subscriptions' => $request->user()->pushSubscriptions()->exists(),
                'prompt_dismissed' => isset($request->user()->preferences['push']['prompt_dismissed_at']),
            ] : null,
            // Cercles : mode courant (famille/amis), cercle courant, liste pour
            // le sélecteur (affiché seulement s'il y en a plusieurs).
            // Pastille « Fiches communes » (propositions à trancher, nouveautés).
            'sharedPeople' => fn () => $request->user() ? app(\App\Services\SharedFiches::class)->counts($request->user()) : null,
            'circle' => fn () => $request->user() ? [
                'mode' => $request->user()->mode(),
                'current' => ($c = $request->user()->currentCircle())
                    ? ['id' => $c->id, 'name' => $c->name, 'kind' => $c->kind]
                    : null,
                'list' => $request->user()->circles()
                    ->map(fn ($h) => ['id' => $h->id, 'name' => $h->name, 'kind' => $h->kind])
                    ->values(),
                'family' => $request->user()->canSeeFamilyDirectory(),
                'can_manage' => $request->user()->isAdmin(),
                // Tout membre peut créer son cercle d'amis.
                'can_create' => ! $request->user()->isGuest() && ! $request->user()->simple_mode,
            ] : null,
            // Langue de l'interface : courante, choix du membre (null = défaut
            // du site) et défaut du site (sélecteur du profil).
            'i18n' => fn () => [
                'locale' => app()->getLocale(),
                'preference' => $request->user()?->localePreference(),
                'default' => \App\Support\SiteSettings::locale(),
                'available' => \App\Support\Locales::SUPPORTED,
            ],
            // Site de démonstration (bandeaux, bouton d'entrée, visite proposée).
            // Démo : les familles proposées à l'entrée (chacune dans sa langue).
            'demo' => config('demo.enabled')
                ? collect(\App\Services\Demo\DemoBuilder::families())->map(fn ($f, $key) => ['key' => $key] + ($f['entry'] ?? []))->values()
                : false,
            // Démo : l'accueil de la première visite (histoire de la famille, choses à essayer).
            'demoWelcome' => fn () => config('demo.enabled') && $request->user()
                ? \Illuminate\Support\Facades\Cache::remember('demo-welcome:'.$request->user()->id, 600, fn () => \App\Services\Demo\DemoBuilder::welcomeFor($request->user()))
                : null,
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
            ],
        ]);
    }
}
