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
        'people.index', 'people.show', 'people.faceAvatar', 'people.avatarImage', 'people.portrait',
        'people.timeline', 'people.kinship',
        'family-tree.index', 'family-tree.data', 'family-tree.subtree',
        'media.file',
        'logout',
        'impersonate.leave',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // Compte « en mémoire » : une session restée ouverte est fermée.
        if ($user?->isMemorial()) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->withErrors(['email' => __('Ce compte est conservé en mémoire : il n\'est plus possible de s\'y connecter.')]);
        }

        if (! $user?->isGuest()) {
            return $next($request);
        }

        if ($user->guestExpired()) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->withErrors(['email' => __('Votre accès invité a expiré.')]);
        }

        if (in_array($request->route()?->getName(), self::ALLOWED_ROUTES, true)) {
            return $next($request);
        }

        if ($request->expectsJson() || ! $request->isMethod('GET')) {
            abort(403, __('Accès invité en lecture seule.'));
        }

        return redirect()->route('family-tree.index');
    }
}
