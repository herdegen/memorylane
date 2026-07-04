<?php

namespace App\Http\Controllers;

use App\Services\MediaService;
use Illuminate\Http\Request;

/**
 * Cible du Web Share Target : reçoit les fichiers envoyés via
 * « Partager → MemoryLane » depuis la galerie du téléphone (PWA installée).
 */
class ShareTargetController extends Controller
{
    public function __construct(
        protected MediaService $mediaService
    ) {}

    public function store(Request $request)
    {
        $request->validate([
            'media'   => ['required', 'array', 'min:1'],
            'media.*' => ['file', 'mimetypes:image/jpeg,image/png,image/gif,image/webp,image/heic,image/heif,video/mp4,video/quicktime,video/x-msvideo,video/x-matroska,video/webm', 'max:512000'],
        ], [
            'media.required' => 'Aucun fichier reçu.',
            'media.*.mimetypes' => 'Seules les photos et vidéos sont acceptées.',
        ]);

        $userId = auth()->id();
        $count = 0;

        foreach ($request->file('media') as $file) {
            $this->mediaService->uploadMedia($file, $userId);
            $count++;
        }

        return redirect()->route('media.index')->with(
            'success',
            $count === 1
                ? 'Votre photo est arrivée dans la galerie.'
                : "Vos {$count} médias sont arrivés dans la galerie."
        );
    }
}
