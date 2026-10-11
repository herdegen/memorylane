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
    public const STEPS = ['upload_tutorial', 'tour', 'pwa'];

    public function store(Request $request, string $step)
    {
        $request->user()->setPreference("onboarding.{$step}", now()->toIso8601String());

        return response()->json(['message' => 'Noté.']);
    }
}
