<?php

namespace App\Http\Controllers;

use App\Mail\InvitationMail;
use App\Models\Invitation;
use App\Models\Person;
use App\Services\InvitationService;
use App\Services\PersonPortraitService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Invitations : page d'arrivée publique « Créer mon accès » (/bienvenue/…)
 * et envoi d'une invitation par un membre depuis la fiche d'un proche.
 */
class InvitationController extends Controller
{
    /** Invitations qu'un membre peut envoyer par jour. */
    private const MEMBER_DAILY_LIMIT = 10;

    public function __construct(private InvitationService $invitations) {}

    /**
     * Page d'arrivée du lien reçu par mail.
     */
    public function show(Request $request, string $token, PersonPortraitService $portraits)
    {
        $invitation = Invitation::findByToken($token);

        if (! $invitation || ! $invitation->isUsable()) {
            return Inertia::render('Auth/Welcome', [
                'state' => $invitation?->status() ?? 'invalid',
            ]);
        }

        $person = $invitation->person;
        $firstName = InvitationMail::firstName($person->first_name ?: $person->name);
        $lastName = $person->last_name ? mb_convert_case(mb_strtolower($person->last_name), MB_CASE_TITLE) : null;

        return Inertia::render('Auth/Welcome', [
            'state' => 'ok',
            'token' => $token,
            'formal' => $invitation->formal,
            'email' => $invitation->email,
            'inviter' => $invitation->inviter ? InvitationMail::firstName($invitation->inviter->name) : null,
            'person' => [
                'first_name' => $firstName,
                'name' => InvitationMail::prettyName($person),
                'birth_year' => $person->birth_date?->year,
                'portrait' => $portraits->dataUri($person, 200),
            ],
            'suggestedName' => trim($firstName.' '.$lastName),
            // Invitation dans un cercle d'amis : on parle du cercle, pas de l'arbre.
            'friendsCircle' => $invitation->household?->isFriends() ? $invitation->household->name : null,
            'loggedInAs' => $request->user()?->name,
        ]);
    }

    /**
     * Crée le compte et connecte la personne.
     */
    public function accept(Request $request, string $token)
    {
        $invitation = Invitation::findByToken($token);
        abort_unless($invitation && $invitation->isUsable(), 410, 'Cette invitation n\'est plus valable.');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'password' => ['required', 'confirmed', Password::min(8)],
        ], [
            'name.required' => 'Indiquez votre prénom et votre nom.',
            'password.required' => 'Choisissez un mot de passe.',
            'password.confirmed' => 'Les deux mots de passe ne correspondent pas.',
            'password.min' => 'Le mot de passe doit faire au moins 8 caractères.',
        ]);

        $user = $this->invitations->accept($invitation, $data);

        // Un autre compte ouvert sur cet appareil (ex. l'admin qui teste).
        if (Auth::check()) {
            Auth::logout();
        }
        Auth::login($user, remember: true);
        $request->session()->regenerate();

        return redirect()->route('dashboard')->with(
            'success',
            'Bienvenue sur MemoryLane, '.InvitationMail::firstName($user->name).' !'
        );
    }

    /**
     * Un membre invite un proche depuis sa fiche.
     */
    public function store(Request $request, Person $person)
    {
        $user = $request->user();
        abort_unless($user->canEditFamilyTree(), 403);

        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'message' => ['nullable', 'string', 'max:1000'],
            'formal' => ['boolean'],
        ], [
            'email.required' => 'Indiquez son adresse e-mail.',
            'email.email' => 'Cette adresse e-mail n\'est pas valide.',
        ]);

        $key = 'member-invitations:'.$user->id;
        if (RateLimiter::tooManyAttempts($key, self::MEMBER_DAILY_LIMIT)) {
            throw ValidationException::withMessages([
                'email' => 'Vous avez envoyé beaucoup d\'invitations aujourd\'hui : réessayez demain.',
            ]);
        }

        try {
            $this->invitations->invite($user, $data['email'], $person, [
                'formal' => $data['formal'] ?? false,
                'message' => $data['message'] ?? null,
            ], keepOnFailure: false);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'message' => 'Le mail n\'a pas pu partir. Réessayez dans un moment, ou prévenez '
                    .InvitationMail::firstName(config('mail.invitations.admin_from_name')).'.',
            ], 502);
        }

        RateLimiter::hit($key, 86400);

        return response()->json(['message' => 'Invitation envoyée.'], 201);
    }
}
