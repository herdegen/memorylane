<?php

namespace App\Console\Commands;

use App\Services\Demo\DemoBuilder;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * Liste de prises de vue d'une famille de la démo, pour générer ses photos
 * (ComfyUI ou autre) : pour chaque photo, le fichier attendu, le format,
 * l'invite (scène, style de l'époque, chaque personne avec son âge à la date
 * de la photo et son apparence) ; plus une fiche « personnage » par personne
 * pour garder les mêmes visages d'une photo à l'autre. Les images générées se
 * déposent ensuite dans database/demo/photos/<famille>/<clé>.jpg : demo:build
 * les prend à la place des images d'attente.
 */
class DemoShotList extends Command
{
    protected $signature = 'demo:shotlist {family=us : clé de la famille (fichier database/demo/<clé>.php)} {--out= : dossier de sortie}';

    protected $description = 'Liste de prises de vue (invites pour la génération d\'images) d\'une famille de la démo';

    private const NEGATIVE = 'text, caption, watermark, logo, signature, extra fingers, deformed hands, distorted faces, duplicate people, anachronistic objects';

    public function handle(): int
    {
        $fam = $this->argument('family');
        $data = DemoBuilder::families()[$fam] ?? null;
        if (! $data) {
            $this->error("Famille inconnue : {$fam}");

            return self::FAILURE;
        }
        $out = $this->option('out') ?: storage_path("app/private/demo/shotlist-{$fam}");
        if (! is_dir($out)) {
            mkdir($out, 0775, true);
        }

        $people = $data['people'];
        foreach ($data['friends'] as $c) {
            $people += array_map(fn ($p) => [$p[0], $p[1], $p[2], $p[3], $p[4], null, null, null, null, null], $c['people']);
        }
        $looks = $data['looks'] ?? [];

        $shots = [];
        foreach ($data['photos'] as $key => $p) {
            if (isset($p['picsum'])) {
                continue; // photo libre déjà choisie
            }
            $date = Carbon::parse($p['date']);
            $cast = [];
            foreach ($p['people'] as $i => $who) {
                $row = $people[$who] ?? null;
                if (! $row) {
                    continue;
                }
                $age = Carbon::parse($row[3])->diffInYears($date);
                $cast[] = [
                    'key' => $who,
                    'name' => "{$row[0]} {$row[1]}",
                    'age' => (int) floor($age),
                    'description' => $this->who((int) floor($age), $row[2], $looks[$who] ?? null),
                ];
            }
            [$w, $h] = ($p['orientation'] ?? 'landscape') === 'portrait' ? [832, 1216] : [1216, 832];
            $shots[] = [
                'key' => $key,
                'file' => "{$key}.jpg",
                'album' => $p['album'] ? $data['albums'][$p['album']]['name'] : null,
                'title' => $p['title'],
                'date' => $date->format('Y-m-d'),
                'place' => $p['place'],
                'width' => $w,
                'height' => $h,
                'people' => $cast,
                'prompt' => $this->prompt($date->year, $p, $cast),
                'negative_prompt' => self::NEGATIVE,
            ];
        }

        $characters = [];
        foreach ($looks as $key => $look) {
            if (isset($people[$key])) {
                $characters[] = ['key' => $key, 'name' => "{$people[$key][0]} {$people[$key][1]}", 'born' => $people[$key][3], 'gender' => $people[$key][2], 'look' => $look];
            }
        }

        file_put_contents("{$out}/shots.json", json_encode(['family' => $data['circle'], 'shots' => $shots, 'characters' => $characters], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $csv = fopen("{$out}/shots.csv", 'w');
        fputcsv($csv, ['file', 'width', 'height', 'album', 'title', 'date', 'place', 'prompt', 'negative_prompt']);
        foreach ($shots as $s) {
            fputcsv($csv, [$s['file'], $s['width'], $s['height'], $s['album'], $s['title'], $s['date'], $s['place'], $s['prompt'], $s['negative_prompt']]);
        }
        fclose($csv);

        $this->info(count($shots).' prises de vue, '.count($characters)." personnages → {$out}/shots.json et shots.csv");
        $this->line("Images à déposer dans database/demo/photos/{$fam}/<clé>.jpg avant demo:build.");

        return self::SUCCESS;
    }

    private function who(int $age, string $gender, ?string $look): string
    {
        $noun = match (true) {
            $age < 2 => $gender === 'F' ? 'baby girl' : 'baby boy',
            $age < 13 => $gender === 'F' ? 'girl' : 'boy',
            $age < 18 => $gender === 'F' ? 'teenage girl' : 'teenage boy',
            default => $gender === 'F' ? 'woman' : 'man',
        };
        $years = $age < 2 ? 'infant' : "{$age}-year-old";

        return trim("{$years} {$noun}".($look ? ", {$look}" : ''));
    }

    private function prompt(int $year, array $p, array $cast): string
    {
        $style = match (true) {
            $year < 1890 => '19th-century tintype photograph, monochrome, soft vignette, period-accurate clothing',
            $year < 1935 => 'vintage sepia photograph, film grain, period-accurate clothing',
            $year < 1960 => 'mid-century black-and-white photograph, film grain',
            $year < 1980 => 'Kodachrome color slide, warm faded colors',
            $year < 2000 => '35mm film snapshot, on-camera flash, warm colors',
            $year < 2012 => 'early digital camera photo, natural colors',
            default => 'modern smartphone photo, natural light, realistic',
        };
        $people = $cast === [] ? '' : ' People, left to right: '.implode('; ', array_map(fn ($c) => $c['description'], $cast)).'.';

        return "{$style}. {$p['scene']}{$people} Place: {$p['place']}, {$year}.";
    }
}
