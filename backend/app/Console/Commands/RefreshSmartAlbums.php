<?php

namespace App\Console\Commands;

use App\Services\SmartAlbumService;
use Illuminate\Console\Command;

class RefreshSmartAlbums extends Command
{
    protected $signature = 'memorylane:refresh-smart-albums';

    protected $description = 'Recalcule le contenu de tous les albums intelligents';

    public function handle(SmartAlbumService $service): int
    {
        $count = $service->refreshAll();

        $this->info("{$count} album(s) intelligent(s) recalculé(s).");

        return self::SUCCESS;
    }
}
