<?php

/**
 * Génère les icônes PWA (PNG) — même dessin que public/icons/icon.svg :
 * tuile ambre arrondie + polaroid trait crème.
 *
 * Usage : php scripts/generate-pwa-icons.php
 */

function drawIcon(int $size): GdImage
{
    $s = fn (float $v) => (int) round($v * $size / 512);

    $img = imagecreatetruecolor($size, $size);
    imagesavealpha($img, true);
    imagealphablending($img, false);
    $transparent = imagecolorallocatealpha($img, 0, 0, 0, 127);
    imagefill($img, 0, 0, $transparent);
    imagealphablending($img, true);

    $amber = imagecolorallocate($img, 0xD9, 0x77, 0x06);
    $cream = imagecolorallocate($img, 0xFF, 0xFB, 0xEB);

    // Tuile arrondie ambre
    $r = $s(115);
    imagefilledrectangle($img, $r, 0, $size - $r - 1, $size - 1, $amber);
    imagefilledrectangle($img, 0, $r, $size - 1, $size - $r - 1, $amber);
    foreach ([[$r, $r], [$size - $r - 1, $r], [$r, $size - $r - 1], [$size - $r - 1, $size - $r - 1]] as [$cx, $cy]) {
        imagefilledellipse($img, $cx, $cy, $r * 2, $r * 2, $amber);
    }

    $stroke = $s(26);

    // Cadre polaroid : rect crème plein, puis on re-remplit l'intérieur en ambre
    $roundedRect = function ($x1, $y1, $x2, $y2, $radius, $color) use ($img) {
        imagefilledrectangle($img, $x1 + $radius, $y1, $x2 - $radius, $y2, $color);
        imagefilledrectangle($img, $x1, $y1 + $radius, $x2, $y2 - $radius, $color);
        foreach ([[$x1 + $radius, $y1 + $radius], [$x2 - $radius, $y1 + $radius], [$x1 + $radius, $y2 - $radius], [$x2 - $radius, $y2 - $radius]] as [$cx, $cy]) {
            imagefilledellipse($img, $cx, $cy, $radius * 2, $radius * 2, $color);
        }
    };

    $roundedRect($s(116), $s(116), $s(396), $s(396), $s(30), $cream);
    $roundedRect($s(116) + $stroke, $s(116) + $stroke, $s(396) - $stroke, $s(396) - $stroke, max(1, $s(30) - $stroke), $amber);

    // Cercle (objectif) : anneau crème
    imagefilledellipse($img, $s(256), $s(220), $s(100), $s(100), $cream);
    imagefilledellipse($img, $s(256), $s(220), $s(100) - $stroke * 2, $s(100) - $stroke * 2, $amber);

    // Montagnes : segments épais à bouts ronds
    $points = [[130, 346], [196, 280], [250, 334], [330, 234], [384, 316]];
    imagesetthickness($img, $stroke);
    for ($i = 0; $i < count($points) - 1; $i++) {
        [$x1, $y1] = $points[$i];
        [$x2, $y2] = $points[$i + 1];
        imageline($img, $s($x1), $s($y1), $s($x2), $s($y2), $cream);
    }
    foreach ($points as [$x, $y]) {
        imagefilledellipse($img, $s($x), $s($y), $stroke, $stroke, $cream);
    }

    return $img;
}

$dir = __DIR__ . '/../public/icons';

foreach ([192, 512] as $size) {
    $img = drawIcon($size);
    imagepng($img, "{$dir}/icon-{$size}.png");
    imagedestroy($img);
    echo "icon-{$size}.png généré\n";
}
