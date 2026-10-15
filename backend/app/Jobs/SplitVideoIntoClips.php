<?php

namespace App\Jobs;

use App\Jobs\Concerns\DownloadsMediaToTemp;
use App\Models\Media;
use App\Services\S3Service;
use FFMpeg\Coordinate\Dimension;
use FFMpeg\Coordinate\TimeCode;
use FFMpeg\Filters\Video\ResizeFilter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Découpe une vidéo source en plusieurs clips.
 *
 * Chaque segment [start, end] devient un Media (type=video) à part entière,
 * fichier MP4 H.264/AAC directement lisible par les navigateurs (donc pas de
 * transcodage web ultérieur). La date de prise de vue et la géolocalisation de
 * la source sont héritées comme valeurs par défaut (éditables ensuite). Une
 * fois la découpe terminée, la source est marquée is_source=true et disparaît
 * de la galerie principale (elle reste accessible depuis ses clips). Les
 * clips héritent aussi des personnes, partages de cercle et tags de la
 * source, et la remplacent dans ses albums (couverture et fond du titre
 * compris).
 *
 * On télécharge le fichier source UNE seule fois (important pour une vidéo
 * longue) puis on ré-ouvre le fichier local pour chaque segment.
 */
class SplitVideoIntoClips implements ShouldQueue
{
    use Queueable, InteractsWithQueue, SerializesModels, DownloadsMediaToTemp;

    public $tries = 2;

    public $timeout = 3600;

    /**
     * @param  array<int, array{start: float, end: float, title?: string|null}>  $segments
     */
    public function __construct(
        public Media $media,
        public array $segments,
    ) {}

    public function handle(S3Service $s3Service): void
    {
        if ($this->media->type !== 'video') {
            return;
        }

        Log::info('SplitVideoIntoClips: Starting', [
            'media_id' => $this->media->id,
            'segments' => count($this->segments),
        ]);

        $tempPath = $this->downloadFileToTemp($s3Service);
        if (! $tempPath) {
            Log::warning('SplitVideoIntoClips: Failed to download source from S3', [
                'media_id' => $this->media->id,
            ]);
            return;
        }

        $this->media->loadMissing('metadata');
        $visibility = in_array($s3Service->getDisk(), ['local', 'public']) ? 'public' : 'private';

        $ffmpeg = \App\Services\FfmpegFactory::ffmpeg($this->timeout);
        $ffprobe = \App\Services\FfmpegFactory::ffprobe();

        $created = 0;
        $clips = [];

        try {
            foreach (array_values($this->segments) as $i => $segment) {
                $start = (float) $segment['start'];
                $end = (float) $segment['end'];
                if ($end <= $start) {
                    continue;
                }
                $duration = $end - $start;

                $outputPath = sys_get_temp_dir() . '/clip_' . uniqid() . '.mp4';

                try {
                    // Ré-ouverture par segment : sinon les filtres s'accumulent
                    // d'une itération à l'autre.
                    $video = $ffmpeg->open($tempPath);
                    $video->filters()->clip(
                        TimeCode::fromSeconds($start),
                        TimeCode::fromSeconds($duration)
                    );

                    // Limite à 1080p comme le transcodage web (poids raisonnable).
                    if (($this->media->width ?? 0) > 1920 || ($this->media->height ?? 0) > 1080) {
                        $video->filters()
                            ->resize(new Dimension(1920, 1080), ResizeFilter::RESIZEMODE_INSET)
                            ->synchronize();
                    }

                    $format = \App\Services\FfmpegFactory::webX264();

                    $video->save($format, $outputPath);

                    $stream = $ffprobe->streams($outputPath)->videos()->first();

                    $filePath = $s3Service->generateFilePath('video', 'mp4');
                    $s3Service->putFile($outputPath, $filePath, $visibility);

                    $title = trim((string) ($segment['title'] ?? ''));
                    $sourceName = pathinfo($this->media->original_name, PATHINFO_FILENAME);
                    $clipName = $title !== ''
                        ? $title . '.mp4'
                        : $sourceName . ' — clip ' . ($i + 1) . '.mp4';

                    $clip = Media::create([
                        'user_id'         => $this->media->user_id,
                        'source_media_id' => $this->media->id,
                        'type'            => 'video',
                        'original_name'   => $clipName,
                        'title'           => $title !== '' ? $title : null,
                        'file_path'       => $filePath,
                        'content_hash'    => @hash_file('sha256', $outputPath) ?: null,
                        'mime_type'       => 'video/mp4',
                        'size'            => filesize($outputPath) ?: 0,
                        'width'           => $stream ? (int) $stream->get('width') : null,
                        'height'          => $stream ? (int) $stream->get('height') : null,
                        'duration'        => (int) round($duration),
                        'clip_start'      => $start,
                        'clip_end'        => $end,
                        'uploaded_at'     => now(),
                        // Hérite de la date de la source (défaut éditable ensuite).
                        'taken_at'        => $this->media->taken_at,
                    ]);

                    // Hérite de la géolocalisation de la source si elle existe.
                    $sourceMeta = $this->media->metadata;
                    if ($sourceMeta && $sourceMeta->latitude !== null && $sourceMeta->longitude !== null) {
                        $clip->metadata()->create([
                            'latitude'  => $sourceMeta->latitude,
                            'longitude' => $sourceMeta->longitude,
                            'altitude'  => $sourceMeta->altitude,
                        ]);
                    }

                    $this->inheritLinks($clip);

                    // Thumbnail (frame) + extraction métadonnées fines. Pas de
                    // transcodage web : le clip est déjà H.264 web-safe.
                    GenerateMediaConversions::dispatch($clip);

                    $clips[] = $clip;
                    $created++;
                } finally {
                    @unlink($outputPath);
                }
            }

            // La source n'est masquée que si au moins un clip a été produit ;
            // ses clips prennent alors sa place dans ses albums.
            if ($created > 0) {
                $this->media->update(['is_source' => true]);
                $this->replaceInAlbums($clips);
            }

            Log::info('SplitVideoIntoClips: Completed', [
                'media_id' => $this->media->id,
                'clips'    => $created,
            ]);
        } catch (\Exception $e) {
            Log::error('SplitVideoIntoClips: Failed', [
                'media_id' => $this->media->id,
                'error'    => $e->getMessage(),
            ]);
            throw $e;
        } finally {
            @unlink($tempPath);
        }
    }

    /** Personnes, partages de cercle et tags de la source. */
    private function inheritLinks(Media $clip): void
    {
        // Personnes : présentes dans la vidéo, sans cadre de visage propre au clip.
        $clip->people()->syncWithoutDetaching(
            $this->media->people()->pluck('people.id')->mapWithKeys(fn ($id) => [$id => ['face_coordinates' => null]])->all()
        );
        $clip->households()->syncWithoutDetaching(
            $this->media->households()->get()->mapWithKeys(fn ($h) => [$h->id => ['added_by' => $h->pivot->added_by]])->all()
        );
        $clip->tags()->syncWithoutDetaching($this->media->tags()->pluck('tags.id')->all());
    }

    /**
     * Les clips remplacent la source dans chacun de ses albums (même rang),
     * y compris comme couverture ou fond du titre.
     *
     * @param  list<Media>  $clips
     */
    private function replaceInAlbums(array $clips): void
    {
        foreach ($this->media->albums()->get() as $album) {
            $album->media()->syncWithoutDetaching(
                collect($clips)->mapWithKeys(fn (Media $c) => [$c->id => ['order' => $album->pivot->order]])->all()
            );
            $album->media()->detach($this->media->id);

            $changes = [];
            if ($album->cover_media_id === $this->media->id) {
                $changes['cover_media_id'] = $clips[0]->id;
            }
            if ($album->hero_media_id === $this->media->id) {
                $changes['hero_media_id'] = $clips[0]->id;
                GenerateHeroClip::dispatch($clips[0]);
            }
            if ($changes) {
                $album->forceFill($changes)->save();
            }
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('SplitVideoIntoClips: Job failed permanently', [
            'media_id' => $this->media->id,
            'error'    => $exception->getMessage(),
        ]);
    }
}
