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
            // Site de démonstration : ajouts, imports, e-mails et suppressions lourdes refusés.
            \App\Http\Middleware\DemoGuard::class,
            // Télévision reliée : reconnexion par son jeton d'appareil.
            \App\Http\Middleware\TvDeviceLogin::class,
            // Langue de l'interface (profil, session, réglage du site).
            \App\Http\Middleware\SetLocale::class,
            \App\Http\Middleware\HandleInertiaRequests::class,
        ]);

        // Avant l'authentification des routes (sinon « auth » renverrait la
        // TV vers /login avant qu'elle ait pu se reconnecter avec son jeton).
        $middleware->appendToPriorityList(
            \Illuminate\Session\Middleware\StartSession::class,
            \App\Http\Middleware\TvDeviceLogin::class,
        );

        // Alias « admin » : réserve une route aux administrateurs.
        $middleware->alias([
            'admin' => \App\Http\Middleware\EnsureAdmin::class,
        ]);

        // Le POST du Web Share Target vient du système Android/iOS,
        // sans token CSRF possible (la route reste protégée par auth)
        $middleware->validateCsrfTokens(except: [
            'share-target',
            // Désabonnement « en un clic » envoyé par Gmail / Apple Mail
            // (RFC 8058) : pas de session, l'URL signée fait foi.
            'newsletter/unsubscribe/*',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
