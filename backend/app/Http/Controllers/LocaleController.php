<?php

namespace App\Http\Controllers;

use App\Support\Locales;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Choix de la langue de l'interface : préférence du membre (null = langue
 * par défaut du site), ou, avant connexion, mémorisée dans la session.
 */
class LocaleController extends Controller
{
    public function update(Request $request)
    {
        $data = $request->validate([
            'locale' => ['nullable', 'string', Rule::in(array_keys(Locales::SUPPORTED))],
        ]);
        $locale = $data['locale'] ?? null;

        if ($user = $request->user()) {
            $user->setPreference('locale', $locale);
        } elseif ($locale) {
            $request->session()->put('locale', $locale);
        } else {
            $request->session()->forget('locale');
        }

        return $request->expectsJson() ? response()->noContent() : back();
    }

    /**
     * Dictionnaire de l'interface pour le navigateur, en script classique
     * (lu avant l'application) ; l'adresse change avec son contenu (?v=),
     * d'où un cache long.
     */
    public function script(string $locale)
    {
        abort_unless(Locales::supports($locale) && $locale !== 'fr', 404);
        $path = lang_path("{$locale}.json");
        $messages = is_file($path) ? json_decode(file_get_contents($path), true) : [];

        return response('window.__ML_I18N__='.json_encode(
            ['locale' => $locale, 'messages' => (object) $messages],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG,
        ).';', 200, [
            'Content-Type' => 'application/javascript; charset=utf-8',
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ]);
    }

    /** Empreinte du dictionnaire (paramètre ?v= de son adresse). */
    public static function version(string $locale): string
    {
        $path = lang_path("{$locale}.json");

        return is_file($path) ? substr(md5((string) filemtime($path).filesize($path)), 0, 10) : '0';
    }
}
