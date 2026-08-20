<?php

namespace Tests\Feature;

use App\Services\ExifExtractor;
use Tests\TestCase;

/**
 * ExifExtractor n'a aucune dépendance externe (pas d'exiftool : extension
 * exif + fileinfo). La logique de parsing est testée via des tableaux EXIF
 * synthétiques (forme exacte de exif_read_data($f, 0, true)), le chemin
 * fichier via des images générées avec GD.
 */
class ExifExtractorTest extends TestCase
{
    /** Sous-classe exposant les méthodes protégées de parsing. */
    private function exposedExtractor(): ExifExtractor
    {
        return new class extends ExifExtractor
        {
            public function parse(array $exifData): array
            {
                return $this->parseExifData($exifData);
            }

            public function gps(array $coordinate, string $hemisphere): float
            {
                return $this->convertGpsCoordinate($coordinate, $hemisphere);
            }

            public function fraction(string|int|float $value): float
            {
                return $this->evaluateFraction($value);
            }

            public function sanitize(array $exifData): array
            {
                return $this->sanitizeExifData($exifData);
            }
        };
    }

    /** Tableau EXIF synthétique complet (sections comme exif_read_data). */
    private function fullExifData(): array
    {
        return [
            'IFD0' => [
                'Make' => 'Canon',
                'Model' => 'Canon EOS R6',
            ],
            'EXIF' => [
                'ISOSpeedRatings' => 400,
                'FNumber' => '28/10',
                'ExposureTime' => '1/125',
                'FocalLength' => '500/10',
                'DateTimeOriginal' => '2023:06:15 14:30:00',
            ],
            'GPS' => [
                'GPSLatitude' => ['48/1', '51/1', '2962/100'],
                'GPSLatitudeRef' => 'N',
                'GPSLongitude' => ['2/1', '17/1', '4016/100'],
                'GPSLongitudeRef' => 'E',
                'GPSAltitude' => '35/1',
                'GPSAltitudeRef' => 0,
            ],
        ];
    }

    private function emptyKeys(): array
    {
        return [
            'exif_data', 'camera_make', 'camera_model', 'iso', 'aperture',
            'shutter_speed', 'focal_length', 'latitude', 'longitude',
            'altitude', 'taken_at',
        ];
    }

    /** JPEG minimal généré avec GD (aucun segment EXIF). */
    private function temporaryJpeg(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'exif-test-') . '.jpg';
        $image = imagecreatetruecolor(8, 8);
        imagefill($image, 0, 0, imagecolorallocate($image, 200, 120, 40));
        imagejpeg($image, $path);
        imagedestroy($image);

        return $path;
    }

    private function temporaryPng(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'exif-test-') . '.png';
        $image = imagecreatetruecolor(8, 8);
        imagepng($image, $path);
        imagedestroy($image);

        return $path;
    }

    public function test_fichier_inexistant_renvoie_les_11_cles_nulles(): void
    {
        $result = (new ExifExtractor())->extract('/nulle/part/photo.jpg');

        $this->assertSame(array_fill_keys($this->emptyKeys(), null), $result);
    }

    public function test_png_non_supporte_renvoie_les_cles_nulles(): void
    {
        $path = $this->temporaryPng();

        try {
            $result = (new ExifExtractor())->extract($path);
            $this->assertSame(array_fill_keys($this->emptyKeys(), null), $result);
        } finally {
            @unlink($path);
        }
    }

    public function test_jpeg_sans_exif_ne_plante_pas(): void
    {
        $path = $this->temporaryJpeg();

        try {
            $result = (new ExifExtractor())->extract($path);

            $this->assertSame($this->emptyKeys(), array_keys($result));
            // Pas de segment EXIF : aucune donnée de prise de vue.
            $this->assertNull($result['camera_make']);
            $this->assertNull($result['taken_at']);
            $this->assertNull($result['latitude']);
        } finally {
            @unlink($path);
        }
    }

    public function test_parse_exif_complet(): void
    {
        $result = $this->exposedExtractor()->parse($this->fullExifData());

        $this->assertSame('Canon', $result['camera_make']);
        $this->assertSame('Canon EOS R6', $result['camera_model']);
        $this->assertSame(400, $result['iso']);
        $this->assertSame(2.8, $result['aperture']);
        // La vitesse d'obturation est conservée brute (fraction lisible).
        $this->assertSame('1/125', $result['shutter_speed']);
        $this->assertSame(50, $result['focal_length']);
        $this->assertEqualsWithDelta(48.858228, $result['latitude'], 0.000001);
        $this->assertEqualsWithDelta(2.294489, $result['longitude'], 0.000001);
        $this->assertEqualsWithDelta(35.0, $result['altitude'], 0.001);
        $this->assertSame('2023-06-15 14:30:00', $result['taken_at']);
        $this->assertIsArray($result['exif_data']);
    }

    public function test_camera_et_iso_en_secours_depuis_la_section_exif(): void
    {
        $result = $this->exposedExtractor()->parse([
            'EXIF' => [
                'Make' => 'Apple',
                'Model' => 'iPhone 15',
                'PhotographicSensitivity' => '125',
            ],
        ]);

        $this->assertSame('Apple', $result['camera_make']);
        $this->assertSame('iPhone 15', $result['camera_model']);
        $this->assertSame(125, $result['iso']);
    }

    public function test_gps_sans_hemisphere_est_ignore(): void
    {
        // GPSLatitudeRef absent : coordonnée inutilisable, on n'invente rien.
        $result = $this->exposedExtractor()->parse([
            'GPS' => [
                'GPSLatitude' => ['48/1', '51/1', '2962/100'],
            ],
        ]);

        $this->assertNull($result['latitude']);
        $this->assertNull($result['longitude']);
    }

    public function test_hemisphere_sud_et_ouest_donnent_des_coordonnees_negatives(): void
    {
        $extractor = $this->exposedExtractor();

        $sud = $extractor->gps(['33/1', '52/1', '0/1'], 'S');
        $ouest = $extractor->gps(['70/1', '40/1', '0/1'], 'W');

        $this->assertEqualsWithDelta(-33.866667, $sud, 0.000001);
        $this->assertEqualsWithDelta(-70.666667, $ouest, 0.000001);
    }

    public function test_altitude_negative_sous_le_niveau_de_la_mer(): void
    {
        $result = $this->exposedExtractor()->parse([
            'GPS' => [
                'GPSAltitude' => '425/10',
                'GPSAltitudeRef' => 1,
            ],
        ]);

        $this->assertEqualsWithDelta(-42.5, $result['altitude'], 0.001);
    }

    public function test_evaluate_fraction(): void
    {
        $extractor = $this->exposedExtractor();

        $this->assertSame(0.25, $extractor->fraction('1/4'));
        $this->assertSame(2.5, $extractor->fraction(2.5));
        $this->assertSame(3.0, $extractor->fraction('3'));
        // Dénominateur nul : 0 plutôt qu'une division par zéro.
        $this->assertSame(0.0, $extractor->fraction('10/0'));
    }

    public function test_date_invalide_renvoie_null(): void
    {
        $result = $this->exposedExtractor()->parse([
            'EXIF' => ['DateTimeOriginal' => 'pas une date'],
        ]);

        $this->assertNull($result['taken_at']);
    }

    public function test_date_en_secours_depuis_ifd0(): void
    {
        $result = $this->exposedExtractor()->parse([
            'IFD0' => ['DateTime' => '2020:01/02 10:00:00'],
        ]);

        // Format non conforme (Y:m:d attendu) : null, pas d'exception.
        $this->assertNull($result['taken_at']);

        $result = $this->exposedExtractor()->parse([
            'IFD0' => ['DateTime' => '2020:01:02 10:00:00'],
        ]);

        $this->assertSame('2020-01-02 10:00:00', $result['taken_at']);
    }

    public function test_sanitize_retire_les_blocs_binaires_et_rend_le_tout_json_encodable(): void
    {
        $sanitized = $this->exposedExtractor()->sanitize([
            'THUMBNAIL' => ['binaire'],
            'IFD0' => [
                'Make' => "Canon\xB0 inval\xFFide",
                'MakerNote' => 'binaire',
            ],
            'EXIF' => [
                'UserComment' => "\x00\xFF",
                'ISOSpeedRatings' => 100,
            ],
        ]);

        $this->assertArrayNotHasKey('THUMBNAIL', $sanitized);
        $this->assertArrayNotHasKey('MakerNote', $sanitized['IFD0']);
        $this->assertArrayNotHasKey('UserComment', $sanitized['EXIF']);
        $this->assertSame(100, $sanitized['EXIF']['ISOSpeedRatings']);
        // Le résultat doit pouvoir partir dans la colonne JSON exif_data.
        $this->assertNotFalse(json_encode($sanitized));
    }
}
