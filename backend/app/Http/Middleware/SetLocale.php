<?php

namespace App\Http\Middleware;

use App\Support\Locales;
use App\Support\SiteSettings;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;

/**
 * Langue de l'interface pour la requête : choix du membre (profil), sinon
 * choix fait avant connexion (session), sinon langue par défaut du site
 * (réglage d'administration).
 */
class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $locale = $request->user()?->preferredLocale();
        if (! $request->user()) {
            $chosen = $request->hasSession() ? $request->session()->get('locale') : null;
            $locale = Locales::supports($chosen) ? $chosen : SiteSettings::locale();
        }

        app()->setLocale($locale);
        Carbon::setLocale($locale);

        return $next($request);
    }
}
