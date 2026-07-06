<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Derrière Traefik (terminaison TLS) : faire confiance au reverse proxy
        // pour détecter HTTPS, l'IP client réelle et générer des URLs correctes.
        $middleware->trustProxies(at: '*', headers:
            \Illuminate\Http\Request::HEADER_X_FORWARDED_FOR |
            \Illuminate\Http\Request::HEADER_X_FORWARDED_HOST |
            \Illuminate\Http\Request::HEADER_X_FORWARDED_PORT |
            \Illuminate\Http\Request::HEADER_X_FORWARDED_PROTO
        );

        $middleware->web(append: [
            \App\Http\Middleware\HandleInertiaRequests::class,
        ]);

        // Le POST du Web Share Target vient du système Android/iOS,
        // sans token CSRF possible (la route reste protégée par auth)
        $middleware->validateCsrfTokens(except: [
            'share-target',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
