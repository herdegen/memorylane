<?php

namespace App\Services\Demo;

use App\Models\Album;
use App\Models\Household;
use App\Models\LifeEvent;
use App\Models\LifeStoryAnswer;
use App\Models\Media;
use App\Models\Person;
use App\Models\PersonRelationship;
use App\Models\Story;
use App\Models\Tag;
use App\Models\User;
use App\Services\AlbumDating;
use App\Services\GeocodeService;
use App\Services\SharedFiches;
use App\Services\MediaService;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Construit le site de démonstration : plusieurs familles fictives sur la
 * même installation, chacune chez elle (database/demo/<famille>.php), avec
 * des ancêtres communs (shared.php) rattachés aux deux familles. Puis recale
 * les dates sur aujourd'hui (DemoFreshener). Ne tourne QUE sur une base de
 * démo.
 */
class DemoBuilder
{
    /** Familles de la démo, dans l'ordre de construction. */
    public const FAMILIES = ['fr', 'us'];

    /** @var array<string, Person> « fr:camille », « shared:jb_arnaud » */
    private array $people = [];

    /** @var array<string, User> « fr:camille » */
    private array $users = [];

    /** @var array<string, Household> cercle famille de chaque famille */
    private array $circles = [];

    /** @var array<string, Media> « fr:phare » */
    private array $photos = [];

    /** @var array<string, Album> « fr:brest » */
    private array $albums = [];

    public function __construct(private MediaService $media, private AlbumDating $albumDating) {}

    /** Refuse tout ce qui n'est pas explicitement la démo. */
    public static function assertDemoDatabase(): void
    {
        $db = (string) config('database.connections.'.config('database.default').'.database');
        if (! config('demo.enabled') || ! str_contains($db, 'demo')) {
            throw new RuntimeException("Refusé : DEMO_MODE doit être actif et la base doit être une base de démo (actuelle : « {$db} »).");
        }
    }

    /** @return array<string, array> données de chaque famille présente */
    public static function families(): array
    {
        $families = [];
        foreach (self::FAMILIES as $key) {
            $path = database_path("demo/{$key}.php");
            if (is_file($path)) {
                $families[$key] = require $path;
            }
        }

        return $families;
    }

    public static function shared(): array
    {
        return require database_path('demo/shared.php');
    }

    /**
     * Lieux de toutes les familles, déjà géocodés : posés dans le cache du
     * géocodage (aucun appel réseau, carte « Dans le temps » immédiate).
     * Rejoué après chaque remise à zéro (le recalage vide le cache).
     */
    public static function seedPlaces(): void
    {
        $places = self::shared()['places'];
        foreach (self::families() as $family) {
            $places += $family['places'];
        }
        foreach ($places as $name => [$lat, $lng]) {
            Cache::forever(GeocodeService::placeKey(GeocodeService::cleanPlace($name)), ['latitude' => $lat, 'longitude' => $lng]);
        }
    }

    /**
     * Accueil de la première visite pour un compte de la démo : l'histoire de
     * sa famille et quelques choses à essayer, liens résolus (« {person:clé} »,
     * « {person:shared:clé} », « {album:clé} »). Null hors démo.
     *
     * @return array{family: string, story: string, try: list<array{icon: string, title: string, text: string, link: string}>}|null
     */
    public static function welcomeFor(User $user): ?array
    {
        foreach (self::families() as $fam => $data) {
            if (! isset($data['welcome']) || ! collect($data['accounts'])->contains('email', $user->email)) {
                continue;
            }
            $resolve = fn (string $link) => preg_replace_callback('/\{(person|album|story):([a-z0-9_:]+)\}/', function ($m) use ($fam, $data) {
                if ($m[1] === 'album') {
                    return (string) Album::where('name', $data['albums'][$m[2]]['name'] ?? '')->value('id');
                }
                if ($m[1] === 'story') {
                    return (string) Story::where('title', $data['stories'][$m[2]]['title'] ?? '')->value('id');
                }
                $ref = str_starts_with($m[2], 'shared:') ? "demo:{$m[2]}" : "demo:{$fam}:{$m[2]}";

                return (string) Person::where('gedcom_id', $ref)->value('id');
            }, $link);

            return [
                'family' => $fam,
                'story' => $data['welcome']['story'],
                'try' => array_map(fn ($item) => ['link' => $resolve($item['link'])] + $item, $data['welcome']['try']),
            ];
        }

        return null;
    }

    /** @return array{admin_email: string, admin_password: string, families: array<string, array{visitor: string, people: int, photos: int}>} */
    public function build(?callable $progress = null): array
    {
        self::assertDemoDatabase();
        $say = $progress ?? fn () => null;
        $families = self::families();
        $shared = self::shared();
        self::seedPlaces();

        $adminPassword = Str::password(20, symbols: false);
        $admin = User::create([
            'name' => 'Administration démo',
            'email' => 'admin@demo.memory-lane.fr',
            'password' => Hash::make($adminPassword),
            'role' => 'admin',
        ]);

        // Comptes et cercle famille de chaque famille d'abord : les fiches
        // créées ensuite entrent dans la famille de leur auteur.
        foreach ($families as $fam => $data) {
            foreach ($data['accounts'] as $key => $account) {
                $this->users["{$fam}:{$key}"] = User::create([
                    'name' => $account['name'],
                    'email' => $account['email'],
                    'password' => Hash::make(Str::password(32)),
                    'role' => 'user',
                    'preferences' => ['locale' => $data['locale']],
                ]);
            }
            $owner = $this->owner($fam, $data);
            $circle = Household::create([
                'name' => $data['circle'], 'kind' => Household::KIND_FAMILY,
                'invite_policy' => Household::INVITE_EVERYONE, 'created_by' => $owner->id,
            ]);
            foreach ($data['accounts'] as $key => $account) {
                if (isset($data['people'][$account['person']])) {
                    $circle->addMember($this->users["{$fam}:{$key}"], $owner);
                }
            }
            $this->circles[$fam] = $circle;
        }

        $say('Ancêtres communs…');
        $first = array_key_first($families);
        $sharedOwner = $this->owner($first, $families[$first]);
        foreach ($shared['people'] as $key => $row) {
            $this->people["shared:{$key}"] = $this->createPerson($row, $sharedOwner, "demo:shared:{$key}");
            foreach ($this->circles as $circle) {
                $circle->people()->syncWithoutDetaching([$this->people["shared:{$key}"]->id => ['added_by' => $sharedOwner->id]]);
            }
        }

        $summary = [];
        foreach ($families as $fam => $data) {
            $say("Famille {$data['circle']}…");
            $owner = $this->owner($fam, $data);
            $circle = $this->circles[$fam];

            foreach ($data['people'] as $key => $row) {
                $person = $this->createPerson($row, $owner, "demo:{$fam}:{$key}");
                $circle->people()->syncWithoutDetaching([$person->id => ['added_by' => $owner->id]]);
                $this->people["{$fam}:{$key}"] = $person;
            }
            foreach ($data['people'] as $key => $row) {
                [$father, $mother] = [$row[7], $row[8]];
                if ($father || $mother) {
                    $this->people["{$fam}:{$key}"]->forceFill([
                        'father_id' => $father ? $this->person($fam, $father)->id : null,
                        'mother_id' => $mother ? $this->person($fam, $mother)->id : null,
                    ])->save();
                }
            }
            foreach ($data['accounts'] as $key => $account) {
                if (isset($data['people'][$account['person']])) {
                    $this->users["{$fam}:{$key}"]->forceFill(['person_id' => $this->person($fam, $account['person'])->id])->save();
                }
            }
            $this->couples($fam, $data['couples'], $data['places']);
            $this->events($fam, $data['events'], $data['places'], $owner);

            $friendCircles = $this->friends($fam, $data);
            $this->addresses($fam, $data);
            foreach ($data['accounts'] as $key => $account) {
                $this->users["{$fam}:{$key}"]->forceFill(['current_household_id' => $circle->id])->save();
            }

            $say('  albums et photos (fichier, téléchargement ou image d’attente, miniatures)…');
            $photos = $this->albumsAndPhotos($fam, $data, $circle, $friendCircles, $say);
            $this->weddingPhotos($fam, $data);
            $this->stories($fam, $data);
            $this->lifeStories($fam, $data);

            $summary[$fam] = [
                'visitor' => $data['accounts'][$data['visitor']]['email'],
                'people' => count($data['people']),
                'photos' => $photos,
            ];
        }

        // Unions et frises des ancêtres communs (une seule fois).
        $this->couples('shared', $shared['couples'], $shared['places']);
        $this->events('shared', $shared['events'], $shared['places'], $sharedOwner);
        $this->proposals($families, $shared);

        app(DemoFreshener::class)->freshen();

        return ['admin_email' => $admin->email, 'admin_password' => $adminPassword, 'families' => $summary];
    }

    /** Compte « propriétaire » des fiches d'une famille (celui qui a monté l'arbre). */
    private function owner(string $fam, array $data): User
    {
        return $this->users[$fam.':'.($data['owner'] ?? $data['visitor'])];
    }

    /** Fiche d'une famille, ou ancêtre commun (« shared:<clé> »). */
    private function person(string $fam, string $key): Person
    {
        $ref = str_starts_with($key, 'shared:') ? $key : "{$fam}:{$key}";

        return $this->people[$ref] ?? throw new RuntimeException("Fiche inconnue : {$ref}");
    }

    private function createPerson(array $row, User $owner, string $ref): Person
    {
        [$first, $last, $gender, $birth, $birthPlace, $death, $deathPlace, , , $maiden] = $row;

        return Person::create([
            'user_id' => $owner->id,
            'first_name' => $first,
            'last_name' => $last,
            'name' => "{$first} {$last}",
            'maiden_name' => $maiden,
            'gender' => $gender,
            'birth_date' => $birth,
            'birth_place' => $birthPlace,
            'death_date' => $death,
            'death_place' => $deathPlace,
            'in_family_directory' => true,
            'gedcom_id' => $ref,
        ]);
    }

    private function couples(string $fam, array $couples, array $places): void
    {
        foreach ($couples as $row) {
            [$a, $b, $type, $date, $place] = $row;
            PersonRelationship::create([
                'person1_id' => $this->person($fam, $a)->id,
                'person2_id' => $this->person($fam, $b)->id,
                'type' => $type,
                'start_date' => $date,
                'start_place' => $place,
                'end_date' => $row[5] ?? null,
            ]);
        }
    }

    private function events(string $fam, array $events, array $places, User $owner): void
    {
        foreach ($events as $row) {
            [$who, $type, $title, $date, $place] = $row;
            [$eventDate, $precision] = $this->partialDate($date);
            [$lat, $lng] = $places[$place] ?? [null, null];
            foreach ((array) $who as $key) {
                LifeEvent::create([
                    'person_id' => $this->person($fam, $key)->id,
                    'user_id' => $owner->id,
                    'type' => $type,
                    'title' => $title,
                    'description' => $row[6] ?? null,
                    'place' => $place,
                    'latitude' => $lat,
                    'longitude' => $lng,
                    'event_date' => $eventDate,
                    'end_date' => isset($row[5]) ? $this->partialDate($row[5])[0] : null,
                    'date_precision' => $precision,
                ]);
            }
        }
    }

    /**
     * Adresses actuelles (une ville, un quartier) : « Où vit la famille » et
     * « Où vivent mes amis », et le point d'arrivée de « Dans le temps ».
     * Coordonnées connues : enregistrement sans géocodage.
     */
    private function addresses(string $fam, array $data): void
    {
        foreach ($data['addresses'] ?? [] as $key => $place) {
            [$lat, $lng] = $data['places'][$place];
            $this->person($fam, $key)->forceFill([
                'address' => $place,
                'address_city' => trim(explode(',', $place)[0]),
                'address_latitude' => $lat,
                'address_longitude' => $lng,
            ])->saveQuietly();
        }
    }

    /** @return array<string, Household> cercles d'amis de la famille */
    private function friends(string $fam, array $data): array
    {
        $visitor = $this->users["{$fam}:{$data['visitor']}"];
        $visitorPerson = $data['accounts'][$data['visitor']]['person'];
        $circles = [];
        foreach ($data['friends'] as $ck => $c) {
            $owner = $this->users["{$fam}:{$c['owner']}"];
            $circle = Household::create(['name' => $c['name'], 'kind' => Household::KIND_FRIENDS, 'invite_policy' => Household::INVITE_EVERYONE, 'created_by' => $owner->id]);
            foreach ($c['people'] as $key => $row) {
                [$first, $last, $gender, $birth, $birthPlace] = $row;
                $person = Person::create([
                    'user_id' => $owner->id, 'first_name' => $first, 'last_name' => $last, 'name' => "{$first} {$last}",
                    'gender' => $gender, 'birth_date' => $birth, 'birth_place' => $birthPlace,
                    'in_family_directory' => false, 'gedcom_id' => "demo:{$fam}:{$key}",
                ]);
                $circle->people()->attach($person->id, ['added_by' => $owner->id]);
                $this->people["{$fam}:{$key}"] = $person;
            }
            // Comptes des amis, avec leur fiche ; le visiteur fait partie du cercle.
            foreach ($data['accounts'] as $key => $account) {
                if (isset($c['people'][$account['person']])) {
                    $this->users["{$fam}:{$key}"]->forceFill(['person_id' => $this->people["{$fam}:{$account['person']}"]->id])->save();
                }
            }
            foreach (array_unique([...$c['members'], $data['visitor']]) as $member) {
                $circle->addMember($this->users["{$fam}:{$member}"], $owner);
            }
            $circle->people()->syncWithoutDetaching([$this->person($fam, $visitorPerson)->id => ['added_by' => $visitor->id]]);
            $circles[$ck] = $circle;
        }

        return $circles;
    }

    private function albumsAndPhotos(string $fam, array $data, Household $family, array $friendCircles, callable $say): int
    {
        $albums = [];
        foreach ($data['albums'] as $key => $a) {
            $owner = $this->users["{$fam}:{$a['owner']}"];
            $albums[$key] = Album::create([
                'user_id' => $owner->id,
                'name' => $a['name'],
                'description' => $a['description'],
                'date' => $a['date'] ?? null,
                'theme' => $a['theme'] ?? $data['album_themes'][$key] ?? null,
            ]);
            $this->albums["{$fam}:{$key}"] = $albums[$key];
            foreach ($data['album_roles'][$key] ?? [] as $role => $who) {
                foreach ($who as $personKey) {
                    DB::table('album_roles')->insertOrIgnore([
                        'album_id' => $albums[$key]->id, 'person_id' => $this->person($fam, $personKey)->id,
                        'role' => $role, 'added_by' => $owner->id, 'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
            }
            $circle = $a['circle'] === 'family' ? $family : $friendCircles[$a['circle']];
            $albums[$key]->households()->attach($circle->id, ['granted_by' => $owner->id]);
        }

        // Miniatures et analyses tout de suite (pas de file d'attente pendant la
        // construction) ; les documents sont décrits dans les données, pas lus.
        config(['queue.default' => 'sync', 'memorylane.documents.ocr' => false]);
        $count = 0;
        foreach ($data['photos'] as $key => $p) {
            $media = $this->importPhoto($fam, $key, $p);
            $count++;
            $say("    {$count}/".count($data['photos'])." {$p['title']}");

            [$lat, $lng] = $data['places'][$p['place']] ?? [null, null];
            $this->photos["{$fam}:{$key}"] = $media;
            $media->forceFill([
                'title' => $p['title'],
                'description' => isset($p['author']) ? "Photo : {$p['author']} (Unsplash, via Lorem Picsum)." : null,
                'taken_at' => Carbon::parse($p['date']),
                'taken_at_precision' => $p['precision'] ?? null,
                'text_checked_at' => now(),
            ] + (isset($p['document']) ? [
                'is_document' => true, 'document_source' => 'manual',
                'transcription' => $p['document'], 'transcription_source' => 'manual',
            ] : []))->save();
            if ($lat !== null) {
                $media->metadata()->updateOrCreate([], ['latitude' => $lat, 'longitude' => $lng]);
            }
            foreach ($p['people'] as $who) {
                $media->people()->syncWithoutDetaching([$this->person($fam, $who)->id]);
            }
            foreach ($p['tags'] as $tagName) {
                $tag = Tag::firstOrCreate(['slug' => Str::slug($tagName)], ['name' => ucfirst($tagName)]);
                $media->tags()->syncWithoutDetaching([$tag->id]);
            }
            $albumCircle = $p['album'] ? $data['albums'][$p['album']]['circle'] : 'family';
            $circle = $albumCircle === 'family' ? $family : $friendCircles[$albumCircle];
            $media->households()->syncWithoutDetaching([$circle->id => ['added_by' => $media->user_id]]);
            if ($p['album']) {
                $albums[$p['album']]->media()->syncWithoutDetaching([$media->id => ['order' => $count]]);
            }
        }
        foreach ($albums as $album) {
            $album->forceFill(['cover_media_id' => $album->media()->orderBy('album_media.order')->value('media.id')])->save();
            if ($album->date) {
                $this->albumDating->applyAlbum($album);
            }
        }

        return $count;
    }

    /**
     * Photo par le circuit normal : fichier choisi (database/demo/photos/
     * <famille>/<clé>.jpg, ex. image générée), sinon Picsum (photos libres
     * sans visage), sinon une image d'attente teintée selon l'époque.
     */
    private function importPhoto(string $fam, string $key, array $p): Media
    {
        $dir = storage_path("app/private/demo/photos/{$fam}");
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $path = "{$dir}/{$key}.jpg";
        $chosen = database_path("demo/photos/{$fam}/".($p['file'] ?? "{$key}.jpg"));

        if (is_file($chosen)) {
            copy($chosen, $path);
        } elseif (isset($p['picsum'])) {
            if (! is_file($path)) {
                $width = 2000;
                $height = (int) round($width * $p['height'] / $p['width']);
                $response = Http::timeout(60)->retry(3, 2000)->get("https://picsum.photos/id/{$p['picsum']}/{$width}/{$height}.jpg");
                if (! $response->successful()) {
                    throw new RuntimeException("Téléchargement impossible : photo {$key} (Picsum {$p['picsum']}).");
                }
                file_put_contents($path, $response->body());
            }
        } else {
            $this->placeholder($path, (int) substr($p['date'], 0, 4), ($p['orientation'] ?? 'landscape') === 'portrait');
        }

        $file = new UploadedFile($path, "{$key}.jpg", 'image/jpeg', null, true);

        return $this->media->uploadMedia($file, $this->users["{$fam}:{$p['uploader']}"]->id);
    }

    /** Image d'attente (en attendant la photo générée) : dégradé sépia pour les archives, couleurs ensuite. */
    private function placeholder(string $path, int $year, bool $portrait): void
    {
        [$w, $h] = $portrait ? [1200, 1600] : [1600, 1200];
        [$from, $to] = match (true) {
            $year < 1930 => [[112, 86, 60], [214, 192, 156]],
            $year < 1975 => [[60, 96, 120], [232, 196, 140]],
            $year < 2000 => [[150, 72, 60], [246, 206, 150]],
            default => [[40, 110, 140], [250, 214, 160]],
        };
        $img = imagecreatetruecolor($w, $h);
        for ($y = 0; $y < $h; $y++) {
            $t = $y / $h;
            $c = array_map(fn ($a, $b) => (int) round($a + ($b - $a) * $t), $from, $to);
            imageline($img, 0, $y, $w, $y, imagecolorallocate($img, ...$c));
        }
        imagejpeg($img, $path, 85);
        imagedestroy($img);
    }

    /** Photo choisie pour une union : le mariage sur la frise, avec son image. */
    private function weddingPhotos(string $fam, array $data): void
    {
        foreach ($data['wedding_photos'] ?? [] as [$a, $b, $photo]) {
            [$p1, $p2] = [$this->person($fam, $a)->id, $this->person($fam, $b)->id];
            PersonRelationship::where(fn ($q) => $q->where('person1_id', $p1)->where('person2_id', $p2))
                ->orWhere(fn ($q) => $q->where('person1_id', $p2)->where('person2_id', $p1))
                ->update(['media_id' => $this->photos["{$fam}:{$photo}"]->id]);
        }
    }

    /** Histoires de famille, publiées, avec chapitres (texte, citation, photo) et questions. */
    private function stories(string $fam, array $data): void
    {
        foreach ($data['stories'] ?? [] as $s) {
            $story = Story::create([
                'user_id' => $this->users["{$fam}:{$s['author']}"]->id,
                'title' => $s['title'],
                'summary' => $s['summary'],
                'kind' => $s['kind'],
                'credits' => $s['credits'] ?? null,
                'album_id' => isset($s['album']) ? $this->albums["{$fam}:{$s['album']}"]->id : null,
                'published_at' => now()->subDays(3),
            ]);
            foreach ($s['people'] as $i => $key) {
                $story->people()->attach($this->person($fam, $key)->id, ['is_main' => $i === 0, 'position' => $i]);
            }
            foreach ($s['chapters'] as $i => $c) {
                $blocks = array_map(fn ($b) => match ($b[0]) {
                    'text' => ['type' => 'text', 'text' => $b[1]],
                    'quote' => ['type' => 'quote', 'text' => $b[1], 'cite' => $b[2] ?? null],
                    'media' => ['type' => 'media', 'media_id' => $this->photos["{$fam}:{$b[1]}"]->id, 'caption' => $b[2] ?? null],
                }, $c['blocks']);
                $story->chapters()->create(['position' => $i, 'title' => $c['title'], 'when_label' => $c['when'] ?? null, 'blocks' => $blocks]);
            }
            foreach ($s['questions'] ?? [] as $i => $text) {
                $story->questions()->create(['position' => $i, 'text' => $text]);
            }
        }
    }

    /** Récit de vie guidé déjà commencé (étapes écrites, à poursuivre par le visiteur). */
    private function lifeStories(string $fam, array $data): void
    {
        foreach ($data['life_stories'] ?? [] as $l) {
            foreach ($l['chapters'] as $chapter => $body) {
                LifeStoryAnswer::create([
                    'person_id' => $this->person($fam, $l['person'])->id,
                    'user_id' => $this->users["{$fam}:{$l['author']}"]->id,
                    'chapter' => $chapter,
                    'status' => 'done',
                    'body' => $body,
                ]);
            }
        }
    }

    /**
     * Fiche commune : une correction proposée par la famille qui n'est pas
     * référente, en attente de la famille référente (page « Fiches communes »).
     */
    private function proposals(array $families, array $shared): void
    {
        $fiches = app(SharedFiches::class);
        foreach ($shared['proposals'] ?? [] as $p) {
            $person = $this->people["shared:{$p['person']}"]->fresh();
            $referent = $fiches->referent($person);
            $from = collect($this->circles)->search(fn ($c) => $c->id !== $referent?->id);
            if ($from === false) {
                continue;
            }
            $user = $this->owner($from, $families[$from]);
            $fields = collect($p['fields'])->map(fn ($new, $field) => [$person->getRawOriginal($field), $new])->all();
            $proposal = $fiches->propose($user, $person, $fields, $p['source'][$families[$from]['locale']] ?? null);
            if (isset($p['message'][$families[$from]['locale']])) {
                $proposal->messages()->create(['user_id' => $user->id, 'body' => $p['message'][$families[$from]['locale']]]);
            }
        }
    }

    /** « 1920 » → (1920-01-01, year) ; « 1975-09 » → (1975-09-01, month). */
    private function partialDate(string $date): array
    {
        return match (substr_count($date, '-')) {
            0 => ["{$date}-01-01", 'year'],
            1 => ["{$date}-01", 'month'],
            default => [$date, 'day'],
        };
    }
}
