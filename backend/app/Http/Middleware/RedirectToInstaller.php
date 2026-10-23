<?php

namespace App\Http\Middleware;

use App\Support\Install\Installer;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Installation en cours (install.sh) : tout mène à l'assistant, tant qu'il
 * n'est pas terminé. Sans installation en cours, ne fait rien.
 */
class RedirectToInstaller
{
    public function handle(Request $request, Closure $next): Response
    {
        // Langue de l'assistant (bascule et dictionnaire) : permises aussi.
        if (! Installer::pending() || $request->is('install', 'install/*', 'up', 'locale', 'i18n/*')) {
            return $next($request);
        }

        return $request->expectsJson()
            ? response()->json(['message' => __('Installation en cours.')], 503)
            : redirect('/install');
    }
}
