<?php

namespace App\Http\Controllers;

use App\Jobs\ImportTakeoutArchive;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;

/**
 * Import d'archives Google Takeout (export « Google Photos » en ZIP).
 */
class TakeoutImportController extends Controller
{
    public function index()
    {
        return Inertia::render('Media/TakeoutImport');
    }

    public function store(Request $request)
    {
        $request->validate([
            'archives' => ['required', 'array', 'min:1'],
            'archives.*' => ['file', 'mimes:zip', 'max:2097152'], // 2 Go
        ], [
            'archives.*.mimes' => 'Seules les archives ZIP de Takeout sont acceptées.',
            'archives.*.max' => 'Archive trop lourde : choisissez des ZIP de 2 Go maximum dans Takeout.',
        ]);

        $dir = storage_path('app/takeout');
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $count = 0;
        foreach ($request->file('archives') as $archive) {
            $path = $dir . '/' . Str::uuid() . '.zip';
            $archive->move($dir, basename($path));

            ImportTakeoutArchive::dispatch(auth()->id(), $path);
            $count++;
        }

        return redirect()->route('media.index')->with(
            'success',
            $count === 1
                ? 'Archive Takeout reçue : l\'import tourne en arrière-plan, vos photos arrivent avec leur géolocalisation.'
                : "{$count} archives Takeout reçues : l'import tourne en arrière-plan."
        );
    }
}
