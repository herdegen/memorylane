<?php

namespace App\Jobs;

use App\Models\Media;
use App\Services\DocumentReader;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Lecture du texte d'une photo envoyée (DocumentReader). File « faces » :
 * même genre de calcul en tâche de fond, sans retarder les envois.
 */
class ReadDocumentText implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 240;

    public function __construct(public Media $media)
    {
        $this->onQueue('faces');
    }

    public function handle(DocumentReader $reader): void
    {
        if (DocumentReader::available() && $this->media->text_checked_at === null) {
            $reader->classify($this->media);
        }
    }
}
