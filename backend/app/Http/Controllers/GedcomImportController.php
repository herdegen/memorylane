<?php

namespace App\Http\Controllers;

use App\Models\GedcomImport;
use App\Services\GedcomImportService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class GedcomImportController extends Controller
{
    public function __construct(private GedcomImportService $importService) {}

    public function index()
    {
        $imports = GedcomImport::where('user_id', auth()->id())
            ->orderBy('created_at', 'desc')
            ->take(10)
            ->get();

        return Inertia::render('FamilyTree/Import', [
            'imports' => $imports,
        ]);
    }

    /**
     * Upload and parse a GEDCOM file.
     */
    public function upload(Request $request)
    {
        $request->validate([
            'file' => 'required|file|max:10240',
        ]);

        $file = $request->file('file');
        $content = file_get_contents($file->getRealPath());
        $filename = $file->getClientOriginalName();

        $import = $this->importService->parseAndCreateSession($content, $filename, auth()->id());
        $suggestions = $this->importService->generateMatchSuggestions($import);

        return response()->json([
            'message' => 'Fichier GEDCOM analyse',
            'import_id' => $import->id,
            'individuals_count' => $import->individuals_count,
            'families_count' => $import->families_count,
            'suggestions' => $suggestions,
        ]);
    }

    /**
     * Get match suggestions for an import session.
     */
    public function review(GedcomImport $gedcomImport)
    {
        if ($gedcomImport->user_id !== auth()->id()) {
            abort(403);
        }

        $suggestions = $this->importService->generateMatchSuggestions($gedcomImport);

        return response()->json([
            'import' => $gedcomImport,
            'suggestions' => $suggestions,
        ]);
    }

    /**
     * Confirm import with user's matching decisions.
     */
    public function confirm(Request $request, GedcomImport $gedcomImport)
    {
        if ($gedcomImport->user_id !== auth()->id()) {
            abort(403);
        }

        if ($gedcomImport->status !== 'matching') {
            return response()->json([
                'message' => 'Cet import a deja ete traite',
            ], 422);
        }

        $validated = $request->validate([
            'decisions' => 'required|array',
            'decisions.*' => 'required|string',
        ]);

        $stats = $this->importService->executeImport($gedcomImport, $validated['decisions']);

        return response()->json([
            'message' => 'Import termine',
            'stats' => $stats,
        ]);
    }
}
