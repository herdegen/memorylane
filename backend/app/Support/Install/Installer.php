<?php

namespace App\Support\Install;

/**
 * État de l'assistant d'installation (installation « chez soi »).
 *
 * L'assistant n'existe que pendant une installation lancée par install.sh :
 * celui-ci dépose un code d'accès dans storage/app/install/token et attend
 * finished.env. Sans ce fichier (production, installation terminée),
 * l'assistant est introuvable.
 */
class Installer
{
    public static function dir(): string
    {
        // Remplaçable pour les tests (jamais le vrai dossier).
        return config('memorylane.install_dir') ?? storage_path('app/install');
    }

    /** Une installation attend l'assistant. */
    public static function pending(): bool
    {
        return is_file(self::dir().'/token') && ! self::finished();
    }

    public static function finished(): bool
    {
        return is_file(self::dir().'/finished.env');
    }

    public static function checkToken(string $given): bool
    {
        $token = trim((string) @file_get_contents(self::dir().'/token'));
        $given = strtoupper(preg_replace('/[\s-]/', '', $given));

        return $token !== '' && hash_equals($token, $given);
    }

    /** Étapes franchies et choix non secrets (les secrets vont dans .env). */
    public static function state(): array
    {
        $raw = @file_get_contents(self::dir().'/state.json');

        return $raw ? (json_decode($raw, true) ?: []) : [];
    }

    public static function remember(array $values): array
    {
        $state = array_replace_recursive(self::state(), $values);
        file_put_contents(self::dir().'/state.json', json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
        @chmod(self::dir().'/state.json', 0600);

        return $state;
    }

    public static function markStep(string $step, array $values = []): array
    {
        $done = self::state()['done'] ?? [];
        $done[$step] = true;

        return self::remember(['done' => $done] + $values);
    }

    /**
     * Fin de l'assistant : les choix que seul install.sh peut appliquer
     * (services Docker, domaine, adresse d'écoute), dans un format simple
     * qu'il relit sans exécuter quoi que ce soit.
     */
    public static function finish(array $profiles, string $domain, string $bind): void
    {
        $lines = [
            'PROFILES='.implode(',', array_filter($profiles, fn ($p) => preg_match('/^[a-z-]+$/', $p))),
            'DOMAIN='.(preg_match('/^[A-Za-z0-9.-]*$/', $domain) ? $domain : ''),
            // Vide : install.sh garde l'adresse d'écoute choisie au lancement.
            'BIND='.($bind === '127.0.0.1' ? '127.0.0.1' : ''),
        ];
        file_put_contents(self::dir().'/finished.env', implode("\n", $lines)."\n", LOCK_EX);
    }
}
