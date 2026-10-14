<?php

namespace App\Console\Commands;

use App\Models\Album;
use App\Services\HeroClipService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Fond du titre d'un album : une photo ou une vidéo de l'album (extrait muet
 * en boucle, préparé ici), ou --off pour revenir au fond par défaut.
 */
class SetAlbumHero extends Command
{
    protected $signature = 'album:hero
        {album : id ou slug de l\'album}
        {media? : id d\'une photo ou d\'une vidéo de l\'album}
        {--start=0 : vidéo : début de l\'extrait, en secondes}
        {--duration=24 : vidéo : durée de l\'extrait, en secondes}
        {--off : revenir au fond par défaut (carte ou couverture)}';

    protected $description = 'Fond du titre d\'un album (photo, ou vidéo en extrait muet en boucle)';

    public function handle(HeroClipService $clips): int
    {
        $key = $this->argument('album');
        $album = Album::where(Str::isUuid($key) ? 'id' : 'slug', $key)->first();
        if (! $album) {
            $this->error('Album introuvable.');

            return self::FAILURE;
        }

        if ($this->option('off')) {
            $album->forceFill(['hero_media_id' => null])->save();
            $this->info("« {$album->name} » : fond par défaut.");

            return self::SUCCESS;
        }

        $id = (string) $this->argument('media');
        $media = Str::isUuid($id) ? $album->media()->whereKey($id)->whereIn('type', ['photo', 'video'])->first() : null;
        if (! $media) {
            $this->error('Donnez l\'id d\'une photo ou d\'une vidéo de cet album.');

            return self::FAILURE;
        }

        if ($media->type === 'video') {
            $clips->generate($media, (float) $this->option('start'), (float) $this->option('duration'));
        }
        $album->forceFill(['hero_media_id' => $media->id])->save();
        $this->info("« {$album->name} » : fond prêt ({$media->type}).");

        return self::SUCCESS;
    }
}
