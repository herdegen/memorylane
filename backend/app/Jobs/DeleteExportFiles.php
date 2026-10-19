<?php

namespace App\Jobs;

use App\Services\S3Service;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Efface les archives d'un export une fois les liens expirés. */
class DeleteExportFiles implements ShouldQueue
{
    use Queueable;

    /** @param list<string> $paths */
    public function __construct(public array $paths) {}

    public function handle(S3Service $s3): void
    {
        foreach ($this->paths as $path) {
            $s3->delete($path);
        }
    }
}
