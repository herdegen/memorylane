<?php

namespace App\Http\Middleware;

use App\Support\Install\Installer;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Assistant d'installation : introuvable hors d'une installation en cours ;
 * ses étapes exigent le code d'accès du terminal (« unlocked »).
 */
class InstallerGate
{
    public function handle(Request $request, Closure $next, string $level = 'open'): Response
    {
        abort_unless(Installer::pending(), 404);

        if ($level === 'unlocked' && ! $request->session()->get('install_unlocked')) {
            return response()->json(['message' => __('Saisissez d\'abord le code affiché dans le terminal.')], 403);
        }

        return $next($request);
    }
}
