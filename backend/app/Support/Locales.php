<?php

namespace App\Support;

/**
 * Langues de l'interface. Le français est la langue source : le texte
 * français sert de clé de traduction (lang/en.json), comme __() le permet.
 * Seul le texte de l'interface est traduit, jamais les données (noms,
 * titres d'albums, récits…).
 */
class Locales
{
    /** Code → nom de la langue dans cette langue (pour les sélecteurs). */
    public const SUPPORTED = [
        'fr' => 'Français',
        'en' => 'English',
    ];

    public static function supports(mixed $locale): bool
    {
        return is_string($locale) && array_key_exists($locale, self::SUPPORTED);
    }

    /**
     * Langue source, à défaut de réglage du site. (Pas config('app.locale') :
     * app()->setLocale() la réécrit à chaque requête.)
     */
    public static function fallback(): string
    {
        return 'fr';
    }

    /** Code de langue pour les formats de dates/nombres (Intl, navigateur). */
    public static function intl(string $locale): string
    {
        return $locale === 'en' ? 'en-GB' : 'fr-FR';
    }
}
