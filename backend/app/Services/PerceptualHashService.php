<?php

namespace App\Services;

use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;

/**
 * Empreinte perceptuelle d'image (dHash « difference hash », 64 bits).
 *
 * Principe : on réduit l'image à 9×8 pixels et, pour chaque ligne, on compare
 * la luminance de chaque pixel à celle de son voisin de droite → 8×8 = 64 bits.
 * Deux images visuellement proches (rafale, recadrage léger, recompression)
 * donnent des empreintes proches au sens de la distance de Hamming, là où un
 * sha256 (content_hash) ne détecte que le doublon binaire EXACT.
 *
 * Sortie : 16 caractères hexadécimaux (64 bits). Réutilisé par
 * GenerateMediaConversions (à l'ingestion) et par la commande de backfill.
 */
class PerceptualHashService
{
    /** Largeur de l'échantillon (hauteur = SAMPLE_H). */
    private const SAMPLE_W = 9;

    private const SAMPLE_H = 8;

    /**
     * Calcule le dHash d'une image (chemin de fichier OU données binaires).
     * Retourne 16 caractères hexa, ou null si l'image est illisible.
     */
    public function fromFile(string $pathOrBinary): ?string
    {
        try {
            $image = (new ImageManager(new Driver))
                ->decode($pathOrBinary)
                ->resize(self::SAMPLE_W, self::SAMPLE_H);

            /** @var \GdImage $gd */
            $gd = $image->core()->native();

            $bits = '';
            for ($y = 0; $y < self::SAMPLE_H; $y++) {
                for ($x = 0; $x < self::SAMPLE_W - 1; $x++) {
                    $bits .= $this->luminance($gd, $x, $y) < $this->luminance($gd, $x + 1, $y) ? '1' : '0';
                }
            }

            return $this->bitsToHex($bits);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Distance de Hamming entre deux empreintes hexa (nombre de bits qui
     * diffèrent, 0 = identiques, 64 = opposées). Comparaison nibble par nibble
     * pour éviter les soucis d'entiers 64 bits de PHP.
     */
    public static function hammingDistance(string $hexA, string $hexB): int
    {
        $len = min(strlen($hexA), strlen($hexB));
        $distance = abs(strlen($hexA) - strlen($hexB)) * 4;

        for ($i = 0; $i < $len; $i++) {
            $xor = hexdec($hexA[$i]) ^ hexdec($hexB[$i]);
            $distance += substr_count(decbin($xor), '1');
        }

        return $distance;
    }

    /** Luminance perçue (Rec. 601) d'un pixel GD. */
    private function luminance(\GdImage $gd, int $x, int $y): float
    {
        $rgb = imagecolorat($gd, $x, $y);
        $r = ($rgb >> 16) & 0xFF;
        $g = ($rgb >> 8) & 0xFF;
        $b = $rgb & 0xFF;

        return 0.299 * $r + 0.587 * $g + 0.114 * $b;
    }

    /** Convertit une chaîne de 64 bits en 16 caractères hexadécimaux. */
    private function bitsToHex(string $bits): string
    {
        $hex = '';
        foreach (str_split($bits, 4) as $nibble) {
            $hex .= dechex(bindec($nibble));
        }

        return $hex;
    }
}
