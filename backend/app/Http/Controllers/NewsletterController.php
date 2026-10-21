<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

/**
 * Désabonnement de la newsletter depuis le mail (sans connexion, URL
 * signée). Le lien du mail ouvre une page avec un bouton : un antivirus de
 * messagerie qui « visite » les liens ne désabonne donc personne. Le POST
 * direct sert au désabonnement en un clic des messageries (List-Unsubscribe).
 */
class NewsletterController extends Controller
{
    public function show(User $user)
    {
        return view('newsletter.unsubscribe', $this->viewData($user));
    }

    public function unsubscribe(User $user)
    {
        $user->setPreference('newsletter', false);

        return view('newsletter.unsubscribe', array_merge($this->viewData($user), ['done' => 'unsubscribed']));
    }

    public function resubscribe(User $user)
    {
        $user->setPreference('newsletter', true);

        return view('newsletter.unsubscribe', array_merge($this->viewData($user), ['done' => 'resubscribed']));
    }

    /** Réglage depuis la page Profil (connecté). */
    public function update(Request $request)
    {
        $enabled = $request->validate(['enabled' => 'required|boolean'])['enabled'];
        $request->user()->setPreference('newsletter', $enabled);

        return response()->json(['newsletter' => $enabled]);
    }

    private function viewData(User $user): array
    {
        return [
            'firstName' => strtok($user->name, ' '),
            'subscribed' => $user->wantsNewsletter(),
            'unsubscribeUrl' => URL::signedRoute('newsletter.unsubscribe', ['user' => $user->id]),
            'resubscribeUrl' => URL::signedRoute('newsletter.resubscribe', ['user' => $user->id]),
            'done' => null,
        ];
    }
}
