<?php

namespace App\Services\Demo;

use App\Models\Album;
use App\Models\Household;
use App\Models\LifeEvent;
use App\Models\Media;
use App\Models\Person;
use App\Models\PersonRelationship;
use App\Models\Tag;
use App\Models\User;
use App\Services\MediaService;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Construit le contenu du site de démonstration (famille fictive Lambert) à
 * partir de database/demo/family.php et photos.php, puis recale les dates sur
 * aujourd'hui (DemoFreshener). Ne tourne QUE sur une base de démo.
 */
class DemoBuilder
{
    /** @var array<string, Person> */
    private array $people = [];

    /** @var array<string, User> */
    private array $users = [];

    private array $places = [];

    public function __construct(private MediaService $media) {}

    /** Refuse tout ce qui n'est pas explicitement la démo. */
    public static function assertDemoDatabase(): void
    {
        $db = (string) config('database.connections.'.config('database.default').'.database');
        if (! config('demo.enabled') || ! str_contains($db, 'demo')) {
            throw new RuntimeException("Refusé : DEMO_MODE doit être actif et la base doit être une base de démo (actuelle : « {$db} »).");
        }
    }

    /** @return array{admin_email: string, admin_password: string, people: int, photos: int} */
    public function build(?callable $progress = null): array
    {
        self::assertDemoDatabase();
        $say = $progress ?? fn () => null;

        $family = require database_path('demo/family.php');
        $catalog = require database_path('demo/photos.php');
        $this->places = $family['places'];

        // Comptes : un admin (pour toi), le visiteur et quelques proches.
        $adminPassword = Str::password(20, symbols: false);
        $admin = User::create([
            'name' => 'Administration démo',
            'email' => 'admin@demo.memory-lane.fr',
            'password' => Hash::make($adminPassword),
            'role' => 'admin',
        ]);
        foreach ($family['accounts'] as $key => $account) {
            $this->users[$key] = User::create([
                'name' => $account['name'],
                'email' => $account['email'],
                'password' => Hash::make(Str::password(32)),
                'role' => 'user',
            ]);
        }
        $owner = $this->users['jean'];

        $say('Fiches de la famille…');
        foreach ($family['people'] as $key => [$first, $last, $gender, $birth, $birthPlace, $death, $deathPlace, , , $maiden]) {
            $this->people[$key] = Person::create([
                'user_id' => $owner->id,
                'first_name' => $first,
                'last_name' => $last,
                'name' => "{$first} {$last}",
                'maiden_name' => $maiden,
                'gender' => $gender,
                'birth_date' => $birth,
                'birth_place' => $birthPlace ? "{$birthPlace}, France" : null,
                'death_date' => $death,
                'death_place' => $deathPlace ? "{$deathPlace}, France" : null,
                'in_family_directory' => true,
            ]);
        }
        foreach ($family['people'] as $key => $row) {
            [$father, $mother] = [$row[7], $row[8]];
            if ($father || $mother) {
                $this->people[$key]->forceFill([
                    'father_id' => $father ? $this->people[$father]->id : null,
                    'mother_id' => $mother ? $this->people[$mother]->id : null,
                ])->save();
            }
        }
        foreach ($family['couples'] as [$a, $b, $type, $date, $place]) {
            PersonRelationship::create([
                'person1_id' => $this->people[$a]->id,
                'person2_id' => $this->people[$b]->id,
                'type' => $type,
                'start_date' => $date,
                'start_place' => "{$place}, France",
            ]);
        }

        // Fiches « moi » des comptes.
        foreach (['camille', 'jean', 'sophie'] as $key) {
            $this->users[$key]->forceFill(['person_id' => $this->people[$key]->id])->save();
        }

        $say('Frises de vie…');
        foreach ($family['events'] as [$who, $type, $title, $date, $place]) {
            [$eventDate, $precision] = $this->partialDate($date);
            [$lat, $lng] = $this->places[$place] ?? [null, null];
            LifeEvent::create([
                'person_id' => $this->people[$who]->id,
                'user_id' => $owner->id,
                'type' => $type,
                'title' => $title,
                'place' => $place,
                'latitude' => $lat,
                'longitude' => $lng,
                'event_date' => $eventDate,
                'date_precision' => $precision,
            ]);
        }

        $say('Cercles…');
        $familyCircle = Household::create(['name' => 'Famille Lambert', 'kind' => Household::KIND_FAMILY, 'invite_policy' => Household::INVITE_EVERYONE, 'created_by' => $owner->id]);
        $familyCircle->makeWelcome();
        foreach ([$admin, $this->users['camille'], $this->users['jean'], $this->users['sophie']] as $u) {
            $familyCircle->addMember($u, $owner);
        }
        $friendsCircle = Household::create(['name' => 'Les copains de fac', 'kind' => Household::KIND_FRIENDS, 'invite_policy' => Household::INVITE_EVERYONE, 'created_by' => $this->users['camille']->id]);
        $friendsCircle->addMember($this->users['camille'], $this->users['camille']);
        $friendsCircle->addMember($this->users['mathilde'], $this->users['camille']);
        foreach ($family['friends'] as $key => [$first, $last, $gender, $birth, $birthPlace]) {
            $this->people[$key] = Person::create([
                'user_id' => $this->users['camille']->id,
                'first_name' => $first, 'last_name' => $last, 'name' => "{$first} {$last}",
                'gender' => $gender, 'birth_date' => $birth, 'birth_place' => "{$birthPlace}, France",
                'in_family_directory' => false,
            ]);
            $friendsCircle->people()->attach($this->people[$key]->id, ['added_by' => $this->users['camille']->id]);
        }
        $friendsCircle->people()->syncWithoutDetaching([$this->people['camille']->id => ['added_by' => $this->users['camille']->id]]);
        $this->users['mathilde']->forceFill(['person_id' => $this->people['mathilde']->id])->save();
        $this->users['camille']->forceFill(['current_household_id' => $familyCircle->id])->save();

        $say('Albums et photos (téléchargement et miniatures)…');
        $albums = [];
        foreach ($catalog['albums'] as $key => $a) {
            $albums[$key] = Album::create([
                'user_id' => $this->users[$a['owner']]->id,
                'name' => $a['name'],
                'description' => $a['description'],
            ]);
            $circle = $a['circle'] === 'friends' ? $friendsCircle : $familyCircle;
            $albums[$key]->households()->attach($circle->id, ['granted_by' => $this->users[$a['owner']]->id]);
        }

        // Miniatures et analyses tout de suite (pas de file d'attente pendant la construction).
        config(['queue.default' => 'sync']);
        $count = 0;
        foreach ($catalog['photos'] as $key => $p) {
            $media = $this->importPhoto($key, $p);
            $count++;
            $say("  {$count}/".count($catalog['photos'])." {$p['title']}");

            [$lat, $lng] = $this->places[$p['place']] ?? [null, null];
            $media->forceFill([
                'title' => $p['title'],
                'description' => "Photo : {$p['author']} (Unsplash, via Lorem Picsum).",
                'taken_at' => Carbon::parse($p['date'].' 15:00'),
            ])->save();
            if ($lat !== null) {
                $media->metadata()->updateOrCreate([], ['latitude' => $lat, 'longitude' => $lng]);
            }
            foreach ($p['people'] as $who) {
                $media->people()->syncWithoutDetaching([$this->people[$who]->id]);
            }
            foreach ($p['tags'] as $tagName) {
                $tag = Tag::firstOrCreate(['slug' => Str::slug($tagName)], ['name' => ucfirst($tagName)]);
                $media->tags()->syncWithoutDetaching([$tag->id]);
            }
            $circle = ($p['album'] && $catalog['albums'][$p['album']]['circle'] === 'friends') ? $friendsCircle : $familyCircle;
            $media->households()->syncWithoutDetaching([$circle->id => ['added_by' => $media->user_id]]);
            if ($p['album']) {
                $albums[$p['album']]->media()->syncWithoutDetaching([$media->id => ['order' => $count]]);
            }
        }
        foreach ($albums as $album) {
            $album->forceFill(['cover_media_id' => $album->media()->orderBy('album_media.order')->value('media.id')])->save();
        }

        app(DemoFreshener::class)->freshen();

        return [
            'admin_email' => $admin->email,
            'admin_password' => $adminPassword,
            'people' => count($this->people),
            'photos' => $count,
        ];
    }

    /** Télécharge la photo (Picsum ou fichier local) et la fait entrer par le circuit normal. */
    private function importPhoto(string $key, array $p): Media
    {
        $dir = storage_path('app/private/demo/photos');
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $path = "{$dir}/{$key}.jpg";

        if (! empty($p['file'])) {
            copy(database_path('demo/photos/'.$p['file']), $path);
        } elseif (! is_file($path)) {
            $width = 2000;
            $height = (int) round($width * $p['height'] / $p['width']);
            $response = Http::timeout(60)->retry(3, 2000)->get("https://picsum.photos/id/{$p['picsum']}/{$width}/{$height}.jpg");
            if (! $response->successful()) {
                throw new RuntimeException("Téléchargement impossible : photo {$key} (Picsum {$p['picsum']}).");
            }
            file_put_contents($path, $response->body());
        }

        $file = new UploadedFile($path, "{$key}.jpg", 'image/jpeg', null, true);

        return $this->media->uploadMedia($file, $this->users[$p['uploader']]->id);
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
