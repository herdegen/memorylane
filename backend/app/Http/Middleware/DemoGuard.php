<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Site de démonstration : refuse les actions de config('demo.blocked_routes')
 * avec un message clair (JSON pour les appels de l'app, retour à la page avec
 * un message sinon). Sans effet hors démo.
 */
class DemoGuard
{
    public const MESSAGE = 'Cette action n\'est pas disponible dans la démonstration : ici, on découvre MemoryLane avec une famille fictive, sans rien ajouter ni supprimer.';

    public function handle(Request $request, Closure $next): Response
    {
        if (! config('demo.enabled') || ! in_array($request->route()?->getName(), config('demo.blocked_routes'), true)) {
            return $next($request);
        }

        if ($request->expectsJson() || $request->header('X-Inertia') === null && ! $request->isMethod('GET')) {
            return response()->json(['message' => self::MESSAGE, 'demo' => true], 403);
        }

        return back()->with('error', self::MESSAGE);
    }
}
