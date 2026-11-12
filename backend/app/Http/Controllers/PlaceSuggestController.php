<?php

namespace App\Http\Controllers;

use App\Support\ExternalHttp;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Suggestions de lieux pendant la saisie, dans le monde entier (Photon,
 * données OpenStreetMap) : ville, région, pays pour distinguer Valence
 * (Espagne) de Valence (Drôme). Le lieu choisi arrive avec ses coordonnées.
 */
class PlaceSuggestController extends Controller
{
    public function __invoke(Request $request)
    {
        $q = trim((string) $request->validate(['q' => 'required|string|min:2|max:120'])['q']);
        $lang = in_array(app()->getLocale(), ['fr', 'en', 'de'], true) ? app()->getLocale() : 'en';

        $places = Cache::remember('place-suggest:'.$lang.':'.md5(mb_strtolower($q)), now()->addDays(30), function () use ($q, $lang) {
            $response = Http::withHeaders(['User-Agent' => ExternalHttp::userAgent()])
                ->timeout(5)
                ->get('https://photon.komoot.io/api/', ['q' => $q, 'lang' => $lang, 'limit' => 8]);
            if (! $response->successful()) {
                return null;
            }

            return collect($response->json('features') ?? [])
                ->map(function ($f) {
                    $p = $f['properties'] ?? [];
                    $street = trim(($p['housenumber'] ?? '').' '.($p['street'] ?? ''));
                    $head = $p['name'] ?? ($street !== '' ? $street : null);
                    if (! $head) {
                        return null;
                    }
                    $parts = array_values(array_unique(array_filter([
                        $head,
                        ($p['city'] ?? null) !== $head ? ($p['postcode'] ?? null ? trim(($p['postcode'] ?? '').' '.($p['city'] ?? '')) : ($p['city'] ?? null)) : null,
                        $p['state'] ?? null,
                        $p['country'] ?? null,
                    ])));

                    return [
                        'label' => implode(', ', $parts),
                        'name' => $head,
                        'detail' => implode(', ', array_slice($parts, 1)),
                        'lat' => $f['geometry']['coordinates'][1] ?? null,
                        'lng' => $f['geometry']['coordinates'][0] ?? null,
                    ];
                })
                ->filter(fn ($p) => $p && $p['lat'] !== null)
                ->unique('label')
                ->values()
                ->all();
        });

        return response()->json($places ?? []);
    }
}
