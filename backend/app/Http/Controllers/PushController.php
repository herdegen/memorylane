<?php

namespace App\Http\Controllers;

use App\Models\PushSubscription;
use App\Models\User;
use App\Notifications\Push\TestPush;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Notifications push : abonnement par appareil, préférences par catégorie,
 * notification de test et fermeture de la carte d'invitation du tableau de
 * bord. Toutes les réponses sont en JSON (appels axios depuis le profil).
 */
class PushController extends Controller
{
    /**
     * Enregistre (ou met à jour) l'abonnement de l'appareil courant.
     * Première activation : toutes les catégories sont ouvertes — l'user a
     * explicitement cliqué « Activer », il affine ensuite s'il le souhaite.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'endpoint' => ['required', 'url', 'max:' . PushSubscription::ENDPOINT_MAX_LENGTH],
            'keys.p256dh' => ['required', 'string', 'max:255'],
            'keys.auth' => ['required', 'string', 'max:255'],
            'content_encoding' => ['nullable', 'string', 'in:aesgcm,aes128gcm'],
        ]);

        $user = $request->user();

        $subscription = $user->updatePushSubscription(
            $validated['endpoint'],
            $validated['keys']['p256dh'],
            $validated['keys']['auth'],
            $validated['content_encoding'] ?? null,
        );
        $subscription->forceFill(['user_agent' => Str::limit((string) $request->userAgent(), 250, '')])->save();

        if (! isset($user->preferences['push'])) {
            $user->setPreference('push', array_fill_keys(User::PUSH_CATEGORIES, true));
        }

        return response()->json([
            'message' => 'Notifications activées sur cet appareil.',
            'subscriptions' => $this->subscriptionList($user),
            'preferences' => $user->pushPreferences(),
        ], 201);
    }

    /**
     * Retire un appareil (le sien uniquement : 404 sinon, pour ne pas révéler
     * l'existence des abonnements des autres).
     */
    public function destroy(Request $request, PushSubscription $subscription)
    {
        abort_unless($request->user()->ownsPushSubscription($subscription), 404);

        $subscription->delete();

        return response()->json([
            'message' => 'Appareil retiré.',
            'subscriptions' => $this->subscriptionList($request->user()),
        ]);
    }

    /**
     * Interrupteurs par catégorie. Fusion : on conserve les autres clés
     * (ex. prompt_dismissed_at).
     */
    public function updatePreferences(Request $request)
    {
        $rules = array_fill_keys(User::PUSH_CATEGORIES, ['required', 'boolean']);
        $validated = $request->validate($rules);

        $user = $request->user();
        $user->setPreference('push', array_merge(
            $user->preferences['push'] ?? [],
            array_map(fn ($v) => (bool) $v, $validated),
        ));

        return response()->json([
            'message' => 'Préférences enregistrées.',
            'preferences' => $user->pushPreferences(),
        ]);
    }

    /** Envoie une notification de test à tous les appareils de l'utilisateur. */
    public function test(Request $request)
    {
        abort_unless($request->user()->pushSubscriptions()->exists(), 422, 'Aucun appareil abonné.');

        $request->user()->notify(new TestPush());

        return response()->json(['message' => 'Notification de test envoyée.']);
    }

    /** « Plus tard » sur la carte du tableau de bord : ne plus la proposer. */
    public function dismissPrompt(Request $request)
    {
        $request->user()->setPreference('push.prompt_dismissed_at', now()->toIso8601String());

        return response()->json(['message' => 'OK']);
    }

    /** @return array<int, array<string, mixed>> */
    public static function subscriptionList(User $user): array
    {
        return $user->pushSubscriptions()
            ->latest()
            ->get()
            ->map(fn (PushSubscription $s) => $s->toSummary())
            ->all();
    }
}
