<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Media;
use App\Services\MediaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Suivi d'un import en arrière-plan (Google Photos, Google Drive) : nombre de
 * médias arrivés depuis `after` (horodatage de lancement), un aperçu des
 * derniers, et l'état terminal posé par le job dans le cache.
 */
trait ReportsImportProgress
{
    protected function importProgress(Request $request, MediaService $mediaService, string $statusKey): JsonResponse
    {
        $after = $request->query('after');

        $query = Media::where('user_id', auth()->id());
        if ($after) {
            $query->where('created_at', '>=', $after);
        }

        $count = (clone $query)->count();

        $items = (clone $query)
            ->latest('created_at')
            ->with('conversions')
            ->limit(12)
            ->get()
            ->map(fn (Media $m) => [
                'id' => $m->id,
                'url' => $mediaService->thumbnailUrl($m),
                'name' => $m->original_name,
            ]);

        $status = Cache::get($statusKey);

        return response()->json([
            'count' => $count,
            'items' => $items,
            'finished' => (bool) ($status['finished'] ?? false),
            'failed' => (bool) ($status['failed'] ?? false),
            'skipped' => (int) ($status['skipped'] ?? 0),
            // Vidéos optimisées en tâche de fond (import Drive) : arrivent après.
            'videos' => (int) ($status['videos'] ?? 0),
        ]);
    }
}
