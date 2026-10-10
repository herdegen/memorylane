<?php

namespace App\Console\Commands;

use App\Http\Controllers\LifeEventController;
use App\Models\Album;
use App\Models\DetectedFace;
use App\Models\LifeEvent;
use App\Models\Media;
use App\Models\Person;
use App\Models\PersonRelationship;
use App\Models\Tag;
use App\Services\MediaService;
use Illuminate\Console\Command;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Intègre un « dossier biographique » (récit + photos + documents d'archive)
 * à la fiche d'une personne, à partir d'un manifeste JSON relu au préalable.
 *
 * Le manifeste n'invente aucune structure : il ne fait que remplir les
 * briques génériques de MemoryLane (moments de vie, albums, médias légendés
 * et tagués, fiche personne, union). Le même format sert donc pour n'importe
 * quelle fiche.
 *
 *   {
 *     "person": "<uuid de la fiche>",
 *     "images_dir": "images",                       // relatif au manifeste
 *     "person_fill":      { "notes": "…" },         // remplit les champs VIDES
 *     "person_overwrite": { "first_name": "…" },    // remplace explicitement
 *     "people_fill":      [ { "id": "<uuid>", "set": { … }, "overwrite": { … } } ],
 *     "new_people":       [ { "key": "x", "first_name": "…", "last_name": "…", … } ],
 *     "relationships":    [ { "person1": "<uuid>", "person2": "<uuid>", "type": "spouse",
 *                            "start_date": "1926-11-16", "start_place": "…" } ],
 *     "albums": [ { "key": "k", "name": "…", "description": "…", "cover": "<clé média>" } ],
 *     "media":  [ { "key": "i02", "file": "i02.jpg", "title": "…", "description": "…",
 *                   "album": "k", "people": ["<uuid>"], "tags": ["Document"],
 *                   "taken_at": "1917-11-20 11:45:00",
 *                   "replaces": "<sha256 d'une version précédente à supprimer>" } ],
 *     "events": [ { "type": "job", "title": "…", "event_date": "1950-01-01",
 *                   "date_precision": "year", "end_date": null, "place": "…",
 *                   "latitude": null, "longitude": null, "description": "…",
 *                   "album": "k", "media": "i02", "photos": ["i02", "i03"] } ],   // galerie du moment
 *     "faces": [ { "media": "i33", "person": "<uuid>",               // visage confirmé posé à la main
 *                  "box": { "x": 35, "y": 9, "width": 24, "height": 18 } } ],   // en % de l'image
 *     "remove_albums": ["Ancien nom d'album"]        // albums du propriétaire à supprimer (médias conservés)
 *   }
 *
 * Rejouable sans doublon : albums retrouvés par nom, médias par empreinte du
 * fichier, moments par (titre, date), tags par nom. Les champs déjà renseignés
 * d'une fiche ne sont jamais écrasés, sauf via « overwrite ». Avec
 * --sync-events, les moments du manifeste font foi : les existants sont mis à
 * jour et les autres moments de la fiche sont supprimés.
 */
class ImportPersonDossier extends Command
{
    protected $signature = 'person:import-dossier
        {manifest : Chemin du manifeste JSON}
        {--dry-run : Valide et affiche le plan sans rien écrire}
        {--sync-events : Les moments du manifeste font foi (mise à jour + suppression des autres)}';

    protected $description = 'Intègre un dossier biographique (moments, albums, photos, documents) à une fiche personne';

    public function handle(MediaService $mediaService): int
    {
        $path = $this->argument('manifest');
        $m = is_file($path) ? json_decode(file_get_contents($path), true) : null;
        if (! is_array($m)) {
            $this->error("Manifeste illisible : {$path}");

            return self::FAILURE;
        }

        $dir = dirname(realpath($path)).'/'.($m['images_dir'] ?? 'images');
        $person = Person::find($m['person'] ?? null);
        if (! $person) {
            $this->error('Fiche introuvable : '.($m['person'] ?? '(non fournie)'));

            return self::FAILURE;
        }

        if ($errors = $this->validatePlan($m, $dir)) {
            foreach ($errors as $e) {
                $this->error($e);
            }

            return self::FAILURE;
        }

        $this->info("Fiche : {$person->name} — ".count($m['albums'] ?? []).' album(s), '
            .count($m['media'] ?? []).' média(s), '.count($m['events'] ?? []).' moment(s), '
            .count($m['new_people'] ?? []).' nouvelle(s) personne(s)');

        if ($this->option('dry-run')) {
            $this->info('Dry-run : plan valide, rien n\'a été écrit.');

            return self::SUCCESS;
        }

        $ownerId = $person->user_id;

        // 1. Fichiers d'abord (S3 hors transaction) : un média déjà importé
        //    (même empreinte) est réutilisé, donc le rejeu ne duplique rien.
        $mediaIds = [];
        foreach ($m['media'] ?? [] as $item) {
            $file = $dir.'/'.$item['file'];
            $hash = hash_file('sha256', $file);
            $media = Media::where('user_id', $ownerId)->where('content_hash', $hash)->first();
            if (! $media) {
                $upload = new UploadedFile($file, Str::slug($item['title'] ?? $item['key']).'.jpg', 'image/jpeg', null, true);
                $media = $mediaService->uploadMedia($upload, $ownerId);
                $this->line("  + {$item['key']} → {$media->id}");
            }
            $mediaIds[$item['key']] = $media->id;

            // Fichier retouché/redécoupé : l'ancienne version (empreinte
            // `replaces`) est supprimée définitivement, fichiers S3 compris.
            // Ses liens (albums, personnes, moments) sont recréés par le
            // manifeste sur la nouvelle version.
            if (! empty($item['replaces']) && $item['replaces'] !== $hash) {
                Media::where('user_id', $ownerId)->where('content_hash', $item['replaces'])
                    ->get()->each(function (Media $old) use ($item) {
                        $this->line("  - {$item['key']} remplace {$old->id}");
                        $old->forceDelete();
                    });
            }
        }

        // 2. Données : tout ou rien.
        DB::transaction(function () use ($m, $person, $ownerId, $mediaIds) {
            $this->fill($person, $m['person_fill'] ?? [], $m['person_overwrite'] ?? []);

            foreach ($m['people_fill'] ?? [] as $row) {
                $this->fill(Person::findOrFail($row['id']), $row['set'] ?? [], $row['overwrite'] ?? []);
            }

            foreach ($m['new_people'] ?? [] as $row) {
                $attrs = collect($row)->except('key')->all() + ['user_id' => $ownerId];
                Person::firstOrCreate(
                    ['first_name' => $attrs['first_name'], 'last_name' => $attrs['last_name'] ?? null, 'father_id' => $attrs['father_id'] ?? null],
                    $attrs
                );
            }

            foreach ($m['relationships'] ?? [] as $row) {
                $rel = PersonRelationship::where(fn ($q) => $q->where('person1_id', $row['person1'])->where('person2_id', $row['person2']))
                    ->orWhere(fn ($q) => $q->where('person1_id', $row['person2'])->where('person2_id', $row['person1']))
                    ->first() ?? new PersonRelationship(['person1_id' => $row['person1'], 'person2_id' => $row['person2'], 'type' => $row['type'] ?? 'spouse']);
                foreach (['start_date', 'start_place'] as $col) {
                    if (empty($rel->{$col}) && ! empty($row[$col])) {
                        $rel->{$col} = $row[$col];
                    }
                }
                $rel->save();
            }

            // Albums devenus inutiles (réorganisation) : retirés, médias conservés.
            foreach ($m['remove_albums'] ?? [] as $name) {
                Album::withTrashed()->where('user_id', $ownerId)->where('name', $name)->get()->each->forceDelete();
            }

            $sync = (bool) $this->option('sync-events');
            $albumIds = [];
            foreach ($m['albums'] ?? [] as $row) {
                $album = Album::firstOrCreate(
                    ['user_id' => $ownerId, 'name' => $row['name']],
                    ['description' => $row['description'] ?? null]
                );
                $albumIds[$row['key']] = $album->id;
            }

            foreach ($m['media'] ?? [] as $item) {
                $media = Media::findOrFail($mediaIds[$item['key']]);
                $media->fill(array_filter([
                    'title' => $item['title'] ?? null,
                    'description' => $item['description'] ?? null,
                    'taken_at' => $item['taken_at'] ?? null,
                ]))->save();

                if (! empty($item['album'])) {
                    Album::findOrFail($albumIds[$item['album']])->media()->syncWithoutDetaching([$media->id]);
                }
                // Avec --sync-events, le manifeste fait aussi foi sur le
                // rangement : un média reclassé quitte les autres albums du dossier.
                if ($sync) {
                    $own = ! empty($item['album']) ? $albumIds[$item['album']] : null;
                    DB::table('album_media')->where('media_id', $media->id)
                        ->whereIn('album_id', array_values(array_diff($albumIds, [$own])))->delete();
                }
                foreach ($item['people'] ?? [] as $personId) {
                    Person::findOrFail($personId)->media()->syncWithoutDetaching([$media->id]);
                }
                foreach ($item['tags'] ?? [] as $name) {
                    $media->tags()->syncWithoutDetaching([Tag::firstOrCreate(['name' => $name])->id]);
                }
            }

            // Visages confirmés à la main : donnent un avatar cadré sur le
            // visage (face-avatar) et nourrissent la reconnaissance.
            foreach ($m['faces'] ?? [] as $face) {
                DetectedFace::firstOrCreate(
                    ['media_id' => $mediaIds[$face['media']], 'person_id' => $face['person'], 'provider' => 'manual'],
                    ['bounding_box' => $face['box'], 'confidence' => 1.0, 'status' => 'matched']
                );
            }

            foreach ($m['albums'] ?? [] as $row) {
                if (! empty($row['cover'])) {
                    Album::whereKey($albumIds[$row['key']])->whereNull('cover_media_id')->update(['cover_media_id' => $mediaIds[$row['cover']]]);
                }
            }

            $kept = [];
            foreach ($m['events'] ?? [] as $ev) {
                $identity = ['person_id' => $person->id, 'title' => $ev['title'], 'event_date' => $ev['event_date']];
                $attrs = [
                    'type' => $ev['type'] ?? 'moment',
                    'description' => $ev['description'] ?? null,
                    'place' => $ev['place'] ?? null,
                    'latitude' => $ev['latitude'] ?? null,
                    'longitude' => $ev['longitude'] ?? null,
                    'end_date' => $ev['end_date'] ?? null,
                    'date_precision' => $ev['date_precision'] ?? 'day',
                    'media_id' => isset($ev['media']) ? $mediaIds[$ev['media']] : null,
                    'album_id' => isset($ev['album']) ? $albumIds[$ev['album']] : null,
                ];

                $event = LifeEvent::where($identity)->first();
                if (! $event) {
                    $event = LifeEvent::create($identity + $attrs + ['user_id' => $ownerId]);
                } elseif ($sync) {
                    $event->update($attrs);
                } else {
                    // Même politique que la fiche : on ne remplit que les
                    // champs vides (ex. illustration perdue après un `replaces`).
                    $event->update(array_filter($attrs, fn ($v, $k) => $v !== null && $event->{$k} === null, ARRAY_FILTER_USE_BOTH));
                }
                $kept[] = $event->id;

                // Galerie du moment : l'ordre du manifeste fait foi.
                if (isset($ev['photos'])) {
                    $pivot = [];
                    foreach (array_values($ev['photos']) as $i => $key) {
                        $pivot[$mediaIds[$key]] = ['order' => $i];
                    }
                    $sync ? $event->photos()->sync($pivot) : $event->photos()->syncWithoutDetaching($pivot);
                }
            }
            if ($sync) {
                $person->lifeEvents()->whereNotIn('id', $kept)->delete();
            }

            if (! empty($m['avatar']) && ! $person->avatar_media_id) {
                $person->update(['avatar_media_id' => $mediaIds[$m['avatar']]]);
            }
        });

        $this->info('Dossier intégré.');

        return self::SUCCESS;
    }

    /**
     * Remplit les champs vides (jamais d'écrasement silencieux), puis applique
     * les remplacements explicites.
     */
    private function fill(Person $person, array $fill, array $overwrite): void
    {
        foreach ($fill as $col => $value) {
            if ($person->{$col} === null || $person->{$col} === '') {
                $person->{$col} = $value;
            }
        }
        foreach ($overwrite as $col => $value) {
            $person->{$col} = $value;
        }
        $person->save();
    }

    /** @return list<string> */
    private function validatePlan(array $m, string $dir): array
    {
        $errors = [];
        $mediaKeys = collect($m['media'] ?? [])->pluck('key');
        $albumKeys = collect($m['albums'] ?? [])->pluck('key');

        foreach ($m['media'] ?? [] as $item) {
            if (! is_file($dir.'/'.$item['file'])) {
                $errors[] = "Fichier manquant : {$item['file']}";
            }
            if (! empty($item['album']) && ! $albumKeys->contains($item['album'])) {
                $errors[] = "Média {$item['key']} : album inconnu « {$item['album']} »";
            }
            foreach ($item['people'] ?? [] as $id) {
                if (! Person::whereKey($id)->exists()) {
                    $errors[] = "Média {$item['key']} : personne inconnue {$id}";
                }
            }
        }
        if ($mediaKeys->duplicates()->isNotEmpty()) {
            $errors[] = 'Clés de média en double : '.$mediaKeys->duplicates()->implode(', ');
        }

        foreach ($m['events'] ?? [] as $ev) {
            $label = $ev['title'] ?? '(sans titre)';
            if (! in_array($ev['type'] ?? 'moment', LifeEventController::TYPES, true)) {
                $errors[] = "Moment « {$label} » : type inconnu « {$ev['type']} »";
            }
            if (empty($ev['title']) || empty($ev['event_date']) || ! strtotime($ev['event_date'])) {
                $errors[] = "Moment « {$label} » : titre ou date invalide";
            }
            if (! in_array($ev['date_precision'] ?? 'day', ['day', 'month', 'year'], true)) {
                $errors[] = "Moment « {$label} » : précision de date inconnue";
            }
            if (isset($ev['media']) && ! $mediaKeys->contains($ev['media'])) {
                $errors[] = "Moment « {$label} » : média inconnu « {$ev['media']} »";
            }
            if (isset($ev['album']) && ! $albumKeys->contains($ev['album'])) {
                $errors[] = "Moment « {$label} » : album inconnu « {$ev['album']} »";
            }
            foreach ($ev['photos'] ?? [] as $key) {
                if (! $mediaKeys->contains($key)) {
                    $errors[] = "Moment « {$label} » : photo inconnue « {$key} »";
                }
            }
        }

        foreach ($m['faces'] ?? [] as $face) {
            if (! $mediaKeys->contains($face['media'] ?? null) || ! Person::whereKey($face['person'] ?? null)->exists()) {
                $errors[] = 'Visage : média ou personne inconnus';
            }
            foreach (['x', 'y', 'width', 'height'] as $k) {
                if (! isset($face['box'][$k]) || ! is_numeric($face['box'][$k])) {
                    $errors[] = "Visage sur « {$face['media']} » : boîte incomplète";
                    break;
                }
            }
        }

        foreach ($m['albums'] ?? [] as $row) {
            if (! empty($row['cover']) && ! $mediaKeys->contains($row['cover'])) {
                $errors[] = "Album « {$row['name']} » : couverture inconnue « {$row['cover']} »";
            }
        }

        return $errors;
    }
}
