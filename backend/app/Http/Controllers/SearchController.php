<?php

namespace App\Http\Controllers;

use App\Models\Album;
use App\Models\Media;
use App\Models\Person;
use App\Models\Tag;
use App\Services\GenealogyService;
use App\Services\MediaService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class SearchController extends Controller
{
    public function __construct(
        protected MediaService $mediaService,
        protected GenealogyService $genealogy,
    ) {}

    /**
     * Recherche unifiée : médias, personnes, albums et tags en une requête.
     */
    public function index(Request $request)
    {
        $validated = $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:100'],
        ]);

        $query = $validated['q'];

        $userId = auth()->id();

        // Médias privés : on contraint l'hydratation aux médias du propriétaire.
        // On élargit le take() car le filtre user_id s'applique après le scoring
        // Scout (sinon on risque de perdre des résultats pertinents).
        $media = $this->safeSearch(fn () => Media::search($query)
            ->query(fn ($builder) => $builder->with('conversions')->where('user_id', $userId))
            ->take(30)
            ->get()
            ->take(8));

        $media->each(function ($item) {
            $item->thumbnail_url = $this->mediaService->thumbnailUrl($item, ['thumbnail', 'small']);
        });

        return response()->json([
            'media' => $media->map(fn ($m) => [
                'id'            => $m->id,
                'original_name' => $m->original_name,
                'title'         => $m->title,
                'type'          => $m->type,
                'thumbnail_url' => $m->thumbnail_url,
            ])->values(),
            'people' => $this->rankedPeople($query),
            'albums' => $this->safeSearch(fn () => Album::search($query)
                ->query(fn ($builder) => $builder->where('user_id', $userId))
                ->take(30)->get()->take(5))
                ->map(fn ($a) => [
                    'id'          => $a->id,
                    'name'        => $a->name,
                    'description' => $a->description,
                ])->values(),
            'tags' => $this->safeSearch(fn () => Tag::search($query)->take(5)->get())
                ->map(fn ($t) => [
                    'id'    => $t->id,
                    'name'  => $t->name,
                    'color' => $t->color,
                ])->values(),
        ]);
    }

    /**
     * Exécute une recherche Scout en isolant les échecs (ex. index Meilisearch
     * absent quand un modèle n'a encore aucun enregistrement) : renvoie une
     * collection vide au lieu de faire échouer toute la recherche.
     */
    private function safeSearch(callable $search): \Illuminate\Support\Collection
    {
        try {
            return collect($search());
        } catch (\Throwable $e) {
            report($e);

            return collect();
        }
    }

    /**
     * Personnes de la recherche globale, re-classées après Scout :
     *   1. pertinence textuelle — l'exact avant les voisins fautés (« rene
     *      kormann » avant « Renée Bormann » ou « Anna Maria ») ;
     *   2. proximité de parenté au user connecté (les proches d'abord) ;
     *   3. date de naissance décroissante (familles récentes = + de photos) ;
     *   4. nom.
     * On élargit le take() Scout pour avoir assez de candidats à re-trier avant
     * de couper à 5. La proximité reste un DÉPARTAGE derrière la pertinence :
     * un proche mal orthographié ne double pas l'exact recherché.
     */
    private function rankedPeople(string $query): \Illuminate\Support\Collection
    {
        $people = $this->safeSearch(fn () => Person::search($query)
            ->query(fn ($b) => $b->with('avatar.conversions')->withMatchedFacesCount())
            ->take(30)->get());

        if ($people->isEmpty()) {
            return collect();
        }

        $distance = $this->genealogy->proximity(auth()->user()->person_id)['distance'];
        $normalizedQuery = $this->normalize($query);

        return $people
            ->sort(function (Person $a, Person $b) use ($distance, $normalizedQuery) {
                $ta = $this->relevanceTier($a->name, $normalizedQuery);
                $tb = $this->relevanceTier($b->name, $normalizedQuery);
                if ($ta !== $tb) {
                    return $ta <=> $tb;
                }

                $pa = $distance[$a->id] ?? PHP_INT_MAX;
                $pb = $distance[$b->id] ?? PHP_INT_MAX;
                if ($pa !== $pb) {
                    return $pa <=> $pb;
                }

                $da = $a->birth_date?->timestamp ?? PHP_INT_MIN;
                $db = $b->birth_date?->timestamp ?? PHP_INT_MIN;
                if ($da !== $db) {
                    return $db <=> $da; // récent d'abord
                }

                return strcmp($a->name, $b->name);
            })
            ->take(5)
            ->map(fn ($p) => [
                'id'         => $p->id,
                'name'       => $p->name,
                'avatar_url' => $this->personAvatarUrl($p),
            ])->values();
    }

    /**
     * Niveau de pertinence textuelle (plus bas = meilleur) : 0 exact, 1 commence
     * par la requête, 2 tous les mots présents, 3 sinon. Insensible aux accents
     * et à la casse (cohérent avec Meilisearch et le helper client).
     */
    private function relevanceTier(string $name, string $normalizedQuery): int
    {
        $n = $this->normalize($name);

        if ($n === $normalizedQuery) {
            return 0;
        }
        if (str_starts_with($n, $normalizedQuery)) {
            return 1;
        }

        $tokens = array_filter(explode(' ', $normalizedQuery), fn ($t) => $t !== '');
        foreach ($tokens as $token) {
            if (! str_contains($n, $token)) {
                return 3;
            }
        }

        return $tokens ? 2 : 3;
    }

    /**
     * Minuscules, sans accents (translittération Str::ascii), espaces normalisés.
     */
    private function normalize(string $s): string
    {
        return preg_replace('/\s+/', ' ', trim(Str::lower(Str::ascii($s))));
    }

    /**
     * URL d'avatar d'une personne : photo de profil signée sinon (fallback)
     * le recadrage du visage tagué (endpoint people.faceAvatar). Nécessite
     * `avatar.conversions` et `matched_faces_count` chargés.
     */
    private function personAvatarUrl(Person $person): ?string
    {
        if ($person->avatar) {
            return route('people.avatarImage', $person);
        }

        if (($person->matched_faces_count ?? 0) > 0) {
            return url("/people/{$person->id}/face-avatar");
        }

        return null;
    }
}
