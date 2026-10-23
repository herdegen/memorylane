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
            'email.required' => __('L\'adresse email est requise.'),
            'email.email' => __('L\'adresse email doit être valide.'),
            'password.required' => __('Le mot de passe est requis.'),
        ]);

        // Anti force brute : 5 essais par adresse et par IP, puis attente.
        $throttleKey = 'login:'.strtolower($credentials['email']).'|'.$request->ip();
        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            $minutes = (int) ceil(RateLimiter::availableIn($throttleKey) / 60);

            return back()->withErrors([
                'email' => trans_choice('Trop de tentatives. Réessayez dans :count minute, ou demandez un lien de connexion par e-mail.|Trop de tentatives. Réessayez dans :count minutes, ou demandez un lien de connexion par e-mail.', $minutes),
            ])->onlyInput('email');
        }

        if (Auth::attempt($credentials, $request->boolean('remember'))) {
            if ($request->user()->isMemorial()) {
                Auth::logout();

                return back()->withErrors(['email' => __('Ce compte est conservé en mémoire : il n\'est plus possible de s\'y connecter.')])->onlyInput('email');
            }
            RateLimiter::clear($throttleKey);
            $request->session()->regenerate();

            return redirect()->intended('/dashboard');
        }

        RateLimiter::hit($throttleKey, 300);

        // Return errors on both fields for better UX
        return back()->withErrors([
            'email' => __('Ces identifiants ne correspondent pas à nos enregistrements.'),
            'password' => __('Veuillez vérifier votre mot de passe.'),
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
            'email.required' => __('L\'adresse email est requise.'),
            'email.email' => __('L\'adresse email doit être valide.'),
        ]);

        $throttleKey = 'magic-link:' . strtolower($request->email) . '|' . $request->ip();

        if (RateLimiter::tooManyAttempts($throttleKey, 3)) {
            return back()->withErrors([
                'email' => __('Trop de demandes. Réessayez dans quelques minutes.'),
            ])->onlyInput('email');
        }

        RateLimiter::hit($throttleKey, 600);

        $user = User::where('email', $request->email)->first();

        // Même réponse dans tous les cas (pas d'énumération des comptes) ;
        // aucun lien pour un compte « en mémoire ».
        if ($user && ! $user->isMemorial()) {
            $user->notify(new MagicLoginLink());
        }

        return back()->with('success', __('Si cette adresse a un compte, un lien de connexion vient de lui être envoyé. Pensez à vérifier vos spams.'));
    }

    /**
     * Log the user in from a signed magic link.
     *
     * La validité de la signature et l'expiration sont vérifiées par le
     * middleware « signed ». Session longue durée (remember token).
     */
    public function loginWithMagicLink(Request $request, User $user)
    {
        // Usage unique : le jeton du lien est consommé à la première ouverture.
        if (! MagicLoginLink::consume($user, (string) $request->query('n'))) {
            return redirect()->route('login')->withErrors([
                'email' => __('Ce lien de connexion a déjà servi ou a expiré. Demandez-en un nouveau.'),
            ]);
        }
        if ($user->isMemorial()) {
            return redirect()->route('login')->withErrors([
                'email' => __('Ce compte est conservé en mémoire : il n\'est plus possible de s\'y connecter.'),
            ]);
        }

        Auth::login($user, remember: true);

        $request->session()->regenerate();

        return redirect()->route('dashboard');
    }

    /**
     * Connexion via un lien d'invitation temporaire : compte invité uniquement.
     */
    public function loginAsGuest(Request $request, User $user, string $person)
    {
        abort_if(! $user->isGuest() || $user->guestExpired(), 403, __('Ce lien d\'invitation a expiré.'));

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
            return redirect()->route('login')->withErrors(['email' => __('Ce lien tablette n\'est plus valable. Demandez-en un nouveau.')]);
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
