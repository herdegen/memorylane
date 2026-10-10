<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Compte invité temporaire (guest_expires_at) : lecture seule des fiches
 * personnes et de l'arbre généalogique, jusqu'à expiration du compte.
 */
class RestrictGuest
{
    private const ALLOWED_ROUTES = [
        'people.index', 'people.show', 'people.faceAvatar', 'people.avatarImage',
        'people.timeline', 'people.kinship',
        'family-tree.index', 'family-tree.data', 'family-tree.subtree',
        'media.file',
        'logout',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user?->isGuest()) {
            return $next($request);
        }

        if ($user->guestExpired()) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->withErrors(['email' => 'Votre accès invité a expiré.']);
        }

        if (in_array($request->route()?->getName(), self::ALLOWED_ROUTES, true)) {
            return $next($request);
        }

        if ($request->expectsJson() || ! $request->isMethod('GET')) {
            abort(403, 'Accès invité en lecture seule.');
        }

        return redirect()->route('family-tree.index');
    }
}
