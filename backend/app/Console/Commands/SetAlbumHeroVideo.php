<?php

namespace App\Console\Commands;

use App\Models\Album;
use App\Services\HeroClipService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Met une vidéo de l'album en fond de son titre (extrait muet en boucle), ou
 * la retire (--off).
 */
class SetAlbumHeroVideo extends Command
{
    protected $signature = 'album:hero-video
        {album : id ou slug de l\'album}
        {media? : id de la vidéo (doit être dans l\'album)}
        {--start=0 : début de l\'extrait, en secondes}
        {--duration=24 : durée de l\'extrait, en secondes}
        {--off : retirer la vidéo de fond}';

    protected $description = 'Vidéo en fond du titre d\'un album (extrait muet en boucle)';

    public function handle(HeroClipService $clips): int
    {
        $key = $this->argument('album');
        $album = Album::where(Str::isUuid($key) ? 'id' : 'slug', $key)->first();
        if (! $album) {
            $this->error('Album introuvable.');

            return self::FAILURE;
        }

        if ($this->option('off')) {
            $album->forceFill(['hero_video_id' => null])->save();
            $this->info("« {$album->name} » : vidéo de fond retirée.");

            return self::SUCCESS;
        }

        $id = (string) $this->argument('media');
        $video = Str::isUuid($id) ? $album->media()->whereKey($id)->where('type', 'video')->first() : null;
        if (! $video) {
            $this->error('Donnez l\'id d\'une vidéo de cet album.');

            return self::FAILURE;
        }

        $clips->generate($video, (float) $this->option('start'), (float) $this->option('duration'));
        $album->forceFill(['hero_video_id' => $video->id])->save();
        $clip = $video->conversions()->where('conversion_name', HeroClipService::CLIP)->first();
        $this->info("« {$album->name} » : fond vidéo prêt (".round($clip->size / 1048576, 1).' Mo).');

        return self::SUCCESS;
    }
}
