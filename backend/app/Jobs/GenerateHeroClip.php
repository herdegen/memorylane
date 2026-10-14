<?php

namespace App\Jobs;

use App\Models\Media;
use App\Services\HeroClipService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Extrait muet en boucle d'une vidéo choisie comme fond de titre d'album. */
class GenerateHeroClip implements ShouldQueue
{
    use Queueable;

    public int $timeout = 900;

    public int $tries = 2;

    public function __construct(public Media $video, public float $start = 0) {}

    public function handle(HeroClipService $clips): void
    {
        $clips->generate($this->video, $this->start);
    }
}
