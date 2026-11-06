<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Demo\DemoBuilder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * « Entrer dans la démo » : connexion au compte partagé de la famille choisie
 * (une famille par langue), sans mot de passe — site de démonstration
 * uniquement.
 */
class DemoController extends Controller
{
    public function enter(Request $request)
    {
        abort_unless(config('demo.enabled'), 404);

        $families = DemoBuilder::families();
        $family = $families[(string) $request->input('family')] ?? reset($families);
        $user = $family ? User::where('email', $family['accounts'][$family['visitor']]['email'])->first() : null;
        abort_unless($user, 503, 'La démonstration est en cours de préparation. Réessayez dans quelques minutes.');

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('dashboard');
    }
}
