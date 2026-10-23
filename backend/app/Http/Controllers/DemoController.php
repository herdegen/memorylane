<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * « Entrer dans la démo » : connexion au compte partagé, sans mot de passe
 * (site de démonstration uniquement).
 */
class DemoController extends Controller
{
    public function enter(Request $request)
    {
        abort_unless(config('demo.enabled'), 404);

        $user = User::where('email', config('demo.user_email'))->first();
        abort_unless($user, 503, 'La démonstration est en cours de préparation. Réessayez dans quelques minutes.');

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('dashboard');
    }
}
