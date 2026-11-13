<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

/**
 * Premiers pas : retient qu'un compte a vu le mini-tuto d'envoi de photos ou
 * la visite guidée (préférences « onboarding.* »), pour ne plus les ouvrir
 * d'office.
 */
class OnboardingController extends Controller
{
    // pwa : l'app a été ouverte depuis son icône (installée) au moins une fois.
    // page_tours : tutos de page activés (nouvel accueil, ou « Visite guidée ») ;
    // page_* : tuto de cette page vu (cf. useTour.js).
    public const STEPS = [
        'upload_tutorial', 'tour', 'pwa', 'page_tours',
        'page_tree', 'page_fiche', 'page_photo', 'page_media', 'page_albums', 'page_stories',
    ];

    public function store(Request $request, string $step)
    {
        $request->user()->setPreference("onboarding.{$step}", now()->toIso8601String());

        return response()->json(['message' => __('Noté.')]);
    }
}
