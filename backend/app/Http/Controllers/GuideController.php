<?php

namespace App\Http\Controllers;

use Inertia\Inertia;

/**
 * Guide d'utilisation (/guide) : page statique (contenu dans Guide.vue) +
 * service des captures d'écran illustratives. Les captures contiennent des
 * photos de famille : elles vivent dans storage/app/guide/ (jamais dans
 * public/, jamais committées — régénérables via scripts/guide-captures.mjs)
 * et ne sont servies qu'aux comptes connectés, comme les médias.
 */
class GuideController extends Controller
{
    public function show()
    {
        return Inertia::render('Guide');
    }

    public function image(string $name)
    {
        // La contrainte de route ([a-z0-9-]+, sans point) interdit déjà les
        // chemins ; ceinture et bretelles contre toute traversée.
        abort_unless(basename($name) === $name, 404);

        $path = storage_path('app/guide/'.$name.'.webp');
        abort_unless(is_file($path), 404);

        // setPrivate() après coup : BinaryFileResponse normalise un
        // Cache-Control passé en en-tête et retomberait sur `public`.
        return response()->file($path, ['Content-Type' => 'image/webp'])
            ->setPrivate()
            ->setMaxAge(3600);
    }
}
