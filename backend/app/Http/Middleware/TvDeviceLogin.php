<?php

namespace App\Http\Middleware;

use App\Models\TvDevice;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Symfony\Component\HttpFoundation\Response;

/**
 * Télévision reliée : sa session expirée, elle se reconnecte d'elle-même avec
 * son jeton d'appareil (cookie chiffré). Jeton inconnu (TV déliée depuis le
 * téléphone) : le cookie est effacé, la TV revient à l'écran du code.
 */
class TvDeviceLogin
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->cookie(TvDevice::COOKIE);

        if (is_string($token) && $token !== '' && ! Auth::check()) {
            $device = TvDevice::linked()->where('token_hash', TvDevice::hashToken($token))->with('user')->first();
            $user = $device?->user;

            if ($user && ! $user->isMemorial() && ! $user->isGuest() && ! $user->simple_mode) {
                Auth::login($user);
                $request->session()->regenerate();
                $request->session()->put('tv_device_id', $device->id);
            } else {
                Cookie::queue(Cookie::forget(TvDevice::COOKIE));
            }
        }

        return $next($request);
    }
}
