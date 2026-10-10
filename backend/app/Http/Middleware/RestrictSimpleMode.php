<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Compte en mode grand-parent : uniquement l'interface simplifiée (/simple),
 * les fichiers médias / avatars / mémos vocaux qu'elle affiche, et la
 * déconnexion. Toute autre page ramène à l'accueil simplifié.
 */
class RestrictSimpleMode
{
    private const ALLOWED_ROUTES = [
        'media.file', 'people.faceAvatar', 'people.avatarImage',
        'voice-notes.store', 'voice-notes.file', 'voice-notes.destroy',
        'logout',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()?->simple_mode) {
            return $next($request);
        }

        $route = (string) $request->route()?->getName();
        if (str_starts_with($route, 'simple.') || in_array($route, self::ALLOWED_ROUTES, true)) {
            return $next($request);
        }

        if ($request->expectsJson() || ! $request->isMethod('GET')) {
            abort(403);
        }

        return redirect()->route('simple.home');
    }
}
