<?php

namespace App\Support\Install;

/**
 * Lecture et écriture de backend/.env par l'assistant d'installation, en
 * gardant les commentaires et l'ordre du fichier. Écriture sur place (même
 * fichier) : d'autres conteneurs le montent tel quel.
 */
class EnvFile
{
    public function __construct(private ?string $path = null)
    {
        $this->path ??= base_path('.env');
    }

    public function get(string $key): ?string
    {
        foreach (file($this->path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            if (str_starts_with($line, $key.'=')) {
                return self::unquote(substr($line, strlen($key) + 1));
            }
        }

        return null;
    }

    /** @param  array<string, string|int|bool|null>  $values */
    public function set(array $values): void
    {
        $lines = file($this->path, FILE_IGNORE_NEW_LINES) ?: [];
        foreach ($values as $key => $value) {
            if (! preg_match('/^[A-Z][A-Z0-9_]*$/', $key)) {
                throw new \InvalidArgumentException("Clé invalide : {$key}");
            }
            $line = $key.'='.self::quote($value);
            $found = false;
            foreach ($lines as $i => $existing) {
                if (str_starts_with($existing, $key.'=')) {
                    $lines[$i] = $line;
                    $found = true;
                }
            }
            if (! $found) {
                $lines[] = $line;
            }
        }
        file_put_contents($this->path, implode("\n", $lines)."\n", LOCK_EX);
    }

    public static function quote(string|int|bool|null $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        $value = str_replace(["\r", "\n"], ' ', (string) $value);
        if ($value === '' || preg_match('#^[A-Za-z0-9_./:@+,=-]+$#', $value)) {
            return $value;
        }
        // Guillemets simples : rien n'est interprété (ni $, ni \).
        if (! str_contains($value, "'")) {
            return "'".$value."'";
        }

        return '"'.str_replace(['\\', '"', '$'], ['\\\\', '\\"', '\\$'], $value).'"';
    }

    private static function unquote(string $raw): string
    {
        if (strlen($raw) >= 2 && $raw[0] === "'" && str_ends_with($raw, "'")) {
            return substr($raw, 1, -1);
        }
        if (strlen($raw) >= 2 && $raw[0] === '"' && str_ends_with($raw, '"')) {
            return stripcslashes(substr($raw, 1, -1));
        }

        return $raw;
    }
}
