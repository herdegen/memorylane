<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * « Se connecter en tant que » (admin) : voir l'appli exactement comme un
 * autre membre, puis revenir à son compte. L'identité de l'admin est gardée
 * en session ; les actions faites pendant ce temps le sont au nom du membre.
 */
class Impersonation
{
    public const SESSION_KEY = 'impersonator_id';

    public function start(User $admin, User $target): void
    {
        abort_unless($admin->isAdmin(), 403, 'Réservé à l\'admin.');
        abort_if($admin->is($target), 422, 'C\'est déjà votre compte.');

        Log::info('Connexion en tant que', ['admin' => $admin->id, 'membre' => $target->id]);
        session()->put(self::SESSION_KEY, $admin->id);
        Auth::guard('web')->login($target);
        session()->regenerate();
    }

    /** Retour au compte admin. Renvoie l'admin, ou null (session déconnectée). */
    public function stop(): ?User
    {
        $adminId = session()->pull(self::SESSION_KEY);
        $admin = $adminId ? User::find($adminId) : null;

        if (! $admin?->isAdmin()) {
            Auth::guard('web')->logout();

            return null;
        }

        Log::info('Fin de connexion en tant que', ['admin' => $admin->id, 'membre' => Auth::id()]);
        Auth::guard('web')->login($admin);
        session()->regenerate();

        return $admin;
    }

    public static function active(): bool
    {
        return session()->has(self::SESSION_KEY);
    }
}
