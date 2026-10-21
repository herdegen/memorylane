<?php

namespace App\Http\Middleware;

use App\Models\TvDevice;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Symfony\Component\HttpFoundation\Response;

/**
 * Session d'une télévision reliée : interface /tv/ecran en lecture seule. Les
 * seules écritures possibles sont les réponses au jeu « Qui est-ce ? ». La
 * TV affiche son propre cercle (choisi sur la TV), sans toucher au cercle
 * courant du téléphone. Télévision déliée : session fermée sur-le-champ.
 */
class RestrictTv
{
    private const ALLOWED_ROUTES = [
        'media.file', 'people.faceAvatar', 'people.avatarImage', 'people.portrait', 'voice-notes.file',
        'vision.image', 'vision.faceCrop',
        'quests.faces.next', 'quests.answer',
        // Frise de vie d'une fiche (lecture seule).
        'people.timeline',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $deviceId = $request->session()->get('tv_device_id');
        $user = $request->user();
        if (! $deviceId || ! $user) {
            return $next($request);
        }

        $device = TvDevice::linked()->whereKey($deviceId)->where('user_id', $user->id)->first();
        if (! $device) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            Cookie::queue(Cookie::forget(TvDevice::COOKIE));

            return $request->expectsJson() ? response()->json(['message' => 'Télévision déliée.'], 401) : redirect()->route('tv.screen');
        }

        // Cercle de la TV, en mémoire seulement (jamais enregistré sur le compte).
        if ($device->household_id && $device->household_id !== $user->current_household_id
            && $user->circles()->contains('id', $device->household_id)) {
            $user->current_household_id = $device->household_id;
            $user->syncOriginalAttribute('current_household_id');
        }

        if (! $device->last_seen_at || $device->last_seen_at->lt(now()->subMinutes(10))) {
            $device->forceFill(['last_seen_at' => now()])->saveQuietly();
        }

        $route = (string) $request->route()?->getName();
        if (str_starts_with($route, 'tv.screen.') || in_array($route, self::ALLOWED_ROUTES, true)) {
            return $next($request);
        }

        if ($request->expectsJson() || ! $request->isMethod('GET')) {
            abort(403, 'La télévision est en lecture seule.');
        }

        return redirect()->route('tv.screen.home');
    }
}
