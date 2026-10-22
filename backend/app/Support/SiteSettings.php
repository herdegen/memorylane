<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Réglages du site (table site_settings), lus via le cache : ils servent à
 * chaque requête (langue par défaut) et ne changent presque jamais.
 */
class SiteSettings
{
    private const CACHE_KEY = 'site-settings';

    public static function get(string $key, mixed $default = null): mixed
    {
        return self::all()[$key] ?? $default;
    }

    public static function set(string $key, mixed $value): void
    {
        DB::table('site_settings')->updateOrInsert(
            ['key' => $key],
            ['value' => json_encode($value), 'updated_at' => now(), 'created_at' => now()],
        );
        Cache::forget(self::CACHE_KEY);
    }

    /** @return array<string,mixed> */
    public static function all(): array
    {
        return Cache::rememberForever(self::CACHE_KEY, fn () => DB::table('site_settings')
            ->pluck('value', 'key')
            ->map(fn ($v) => json_decode((string) $v, true))
            ->all());
    }

    /** Langue de l'interface par défaut (visiteurs, membres sans préférence). */
    public static function locale(): string
    {
        $locale = self::get('locale');

        return Locales::supports($locale) ? $locale : Locales::fallback();
    }
}
