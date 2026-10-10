<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Notifications\MagicLoginLink;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Inertia;

class AuthController extends Controller
{
    /**
     * Display the login form.
     */
    public function showLogin()
    {
        return Inertia::render('Auth/Login');
    }

    /**
     * Handle login request.
     */
    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required'],
        ], [
            'email.required' => 'L\'adresse email est requise.',
            'email.email' => 'L\'adresse email doit être valide.',
            'password.required' => 'Le mot de passe est requis.',
        ]);

        if (Auth::attempt($credentials, $request->boolean('remember'))) {
            $request->session()->regenerate();

            return redirect()->intended('/dashboard');
        }

        // Return errors on both fields for better UX
        return back()->withErrors([
            'email' => 'Ces identifiants ne correspondent pas à nos enregistrements.',
            'password' => 'Veuillez vérifier votre mot de passe.',
        ])->onlyInput('email');
    }

    /**
     * Send a magic login link by e-mail.
     *
     * Réponse identique que l'adresse existe ou non : on ne révèle pas
     * quelles adresses ont un compte.
     */
    public function sendMagicLink(Request $request)
    {
        $request->validate([
            'email' => ['required', 'email'],
        ], [
            'email.required' => 'L\'adresse email est requise.',
            'email.email' => 'L\'adresse email doit être valide.',
        ]);

        $throttleKey = 'magic-link:' . strtolower($request->email) . '|' . $request->ip();

        if (RateLimiter::tooManyAttempts($throttleKey, 3)) {
            return back()->withErrors([
                'email' => 'Trop de demandes. Réessayez dans quelques minutes.',
            ])->onlyInput('email');
        }

        RateLimiter::hit($throttleKey, 600);

        $user = User::where('email', $request->email)->first();

        if ($user) {
            $user->notify(new MagicLoginLink());
        }

        return back()->with('success', 'Si cette adresse a un compte, un lien de connexion vient de lui être envoyé. Pensez à vérifier vos spams.');
    }

    /**
     * Log the user in from a signed magic link.
     *
     * La validité de la signature et l'expiration sont vérifiées par le
     * middleware « signed ». Session longue durée (remember token).
     */
    public function loginWithMagicLink(Request $request, User $user)
    {
        Auth::login($user, remember: true);

        $request->session()->regenerate();

        return redirect()->route('dashboard');
    }

    /**
     * Connexion via un lien d'invitation temporaire : compte invité uniquement.
     */
    public function loginAsGuest(Request $request, User $user, string $person)
    {
        abort_if(! $user->isGuest() || $user->guestExpired(), 403, 'Ce lien d\'invitation a expiré.');

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('people.show', $person);
    }

    /**
     * Mode grand-parent : connexion « pour toujours » sur la tablette via le
     * lien sans mot de passe (tablet:create). Jeton comparé par son hash.
     */
    public function loginWithTabletLink(Request $request, string $token)
    {
        $user = User::where('tablet_token_hash', hash('sha256', $token))
            ->where('simple_mode', true)
            ->first();

        if (! $user) {
            return redirect()->route('login')->withErrors(['email' => 'Ce lien tablette n\'est plus valable. Demandez-en un nouveau.']);
        }

        Auth::login($user, remember: true);
        $request->session()->regenerate();

        return redirect()->route('simple.home');
    }

    /**
     * Handle logout request.
     */
    public function logout(Request $request)
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/login');
    }
}
