<?php

namespace Tests\Feature;

use App\Jobs\AnalyzeFaces;
use App\Models\DetectedFace;
use App\Models\Media;
use App\Models\Person;
use App\Models\User;
use App\Services\Vision\DisputedFaceResolver;
use App\Services\Vision\FaceMatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Moteur InsightFace (service docker/faces simulé) : analyse côté serveur
 * intégrée SANS RIEN PERDRE, seuils stricts, revue des identifications.
 */
class InsightFaceTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        config(['memorylane.faces.engine' => 'insightface', 'memorylane.faces.auto_match' => true]);
        Storage::fake(config('filesystems.default'));
        $this->user = User::factory()->create();
    }

    /** Vecteur unitaire 512 proche de l'axe $axis (bruit $noise sur l'axe $noiseAxis). */
    private function vec(int $axis, float $noise = 0.0, int $noiseAxis = 511): array
    {
        $v = array_fill(0, 512, 0.0);
        $v[$axis] = 1.0;
        $v[$noiseAxis] += $noise;
        $n = sqrt(array_sum(array_map(fn ($x) => $x * $x, $v)));

        return array_map(fn ($x) => $x / $n, $v);
    }

    private function photo(string $takenAt = '2020-01-01'): Media
    {
        $media = Media::factory()->create([
            'user_id' => $this->user->id, 'type' => 'photo', 'mime_type' => 'image/jpeg',
            'file_path' => 'media/photos/'.uniqid().'.jpg', 'taken_at' => $takenAt,
        ]);
        Storage::disk(config('filesystems.default'))->put($media->file_path, 'fausse-image');

        return $media;
    }

    private function face(Media $media, array $box, ?Person $person = null, string $status = 'unmatched', ?array $v2 = null): DetectedFace
    {
        return DetectedFace::create([
            'media_id' => $media->id, 'bounding_box' => $box, 'provider' => 'faceapi',
            'status' => $person ? 'matched' : $status, 'person_id' => $person?->id,
            'embedding' => array_fill(0, 128, 0.1), 'embedding_v2' => $v2,
        ]);
    }

    /** Réponse du service : boîtes en pixels d'une image 1000×1000. */
    private function fakeService(array $faces, ?array $embed = null): void
    {
        Http::fake([
            '*/analyze' => Http::response(['width' => 1000, 'height' => 1000, 'faces' => $faces]),
            '*/embed-many*' => Http::response(['width' => 1000, 'height' => 1000, 'faces' => [$embed]]),
            '*/embed?*' => Http::response(['width' => 1000, 'height' => 1000, 'face' => $embed]),
        ]);
    }

    private function detection(array $pxBox, array $embedding, int $age = 30): array
    {
        return ['box' => $pxBox, 'score' => 0.9, 'kps' => [], 'embedding' => $embedding, 'age' => $age, 'sex' => 'M'];
    }

    public function test_reanalysis_keeps_identifications_and_adds_missing_faces(): void
    {
        $person = Person::create(['user_id' => $this->user->id, 'name' => 'Léa']);
        $media = $this->photo();
        $known = $this->face($media, ['x' => 10, 'y' => 10, 'width' => 10, 'height' => 12], $person);
        $missed = $this->face($media, ['x' => 70, 'y' => 70, 'width' => 8, 'height' => 8]);
        $dismissed = $this->face($media, ['x' => 40, 'y' => 40, 'width' => 5, 'height' => 5], status: 'dismissed');

        $this->fakeService(
            [
                $this->detection([105, 102, 198, 215], $this->vec(1)),          // ≈ le visage identifié
                $this->detection([400, 400, 450, 452], $this->vec(2)),          // ≈ le visage ignoré
                $this->detection([500, 100, 560, 170], $this->vec(3)),          // nouveau visage
                $this->detection([900, 900, 905, 905], $this->vec(4)),          // trop petit : ignoré
            ],
            ['embedding' => $this->vec(5), 'age' => null, 'source' => 'crop'],
        );

        AnalyzeFaces::dispatchSync($media);

        $known->refresh();
        $this->assertSame($person->id, $known->person_id);
        $this->assertSame('matched', $known->status);
        $this->assertSame(['x' => 10, 'y' => 10, 'width' => 10, 'height' => 12], $known->bounding_box);
        $this->assertCount(512, $known->embedding_v2);
        $this->assertCount(128, $known->embedding, 'empreinte face-api conservée');

        $this->assertSame('dismissed', $dismissed->fresh()->status);
        $this->assertSame('dismissed', $missed->fresh()->status, 'ancien cadre non identifié sans visage : masqué');
        $this->assertSame('faceapi-unconfirmed', $missed->fresh()->engine);

        $this->assertSame(4, $media->detectedFaces()->count(), '3 existants + 1 nouveau, rien de supprimé');
        $new = $media->detectedFaces()->where('provider', 'insightface')->sole();
        $this->assertSame('unmatched', $new->status);
        $this->assertEqualsWithDelta(50.0, $new->bounding_box['x'], 0.01);

        // Deuxième passe : aucun doublon.
        AnalyzeFaces::dispatchSync($media);
        $this->assertSame(4, $media->detectedFaces()->count());
    }

    public function test_identified_face_without_visible_face_is_kept_and_flagged(): void
    {
        $person = Person::create(['user_id' => $this->user->id, 'name' => 'Léa']);
        foreach ([0.0, 0.1] as $n) {
            $this->face($this->photo(), ['x' => 1, 'y' => 1, 'width' => 5, 'height' => 5], $person, v2: $this->vec(1, $n));
        }
        $media = $this->photo();
        $back = $this->face($media, ['x' => 70, 'y' => 70, 'width' => 8, 'height' => 8], $person);
        $drawn = DetectedFace::create(['media_id' => $media->id, 'bounding_box' => ['x' => 30, 'y' => 30, 'width' => 8, 'height' => 8], 'provider' => 'manual', 'status' => 'unmatched']);
        $this->fakeService([], ['embedding' => $this->vec(9), 'age' => null, 'source' => 'crop']);

        AnalyzeFaces::dispatchSync($media);

        $this->assertSame($person->id, $back->fresh()->person_id, 'identification jamais retirée');
        $this->assertSame('insightface-crop', $back->fresh()->engine);
        $this->assertSame('unmatched', $drawn->fresh()->status, 'visage dessiné à la main jamais masqué');
        $this->assertSame('noface', app(DisputedFaceResolver::class)->analysis()[$back->id]['reason']);
    }

    public function test_large_old_box_is_matched_by_detection_center(): void
    {
        $person = Person::create(['user_id' => $this->user->id, 'name' => 'Léa']);
        $media = $this->photo();
        // Ancien cadre large (cheveux compris) ; la détection est plus serrée.
        $old = $this->face($media, ['x' => 10, 'y' => 10, 'width' => 30, 'height' => 40], $person);
        $this->fakeService([$this->detection([180, 250, 260, 340], $this->vec(1))]);

        AnalyzeFaces::dispatchSync($media);

        $this->assertSame('insightface', $old->fresh()->engine);
        $this->assertSame(1, $media->detectedFaces()->count(), 'pas de visage ajouté en doublon');
    }

    public function test_consolidation_merges_duplicates_hides_guesses_and_flags_identified(): void
    {
        $lea = Person::create(['user_id' => $this->user->id, 'name' => 'Léa']);
        $media = $this->photo();
        $big = $this->face($media, ['x' => 10, 'y' => 10, 'width' => 30, 'height' => 40], $lea);
        $big->forceFill(['engine' => 'insightface-box', 'embedding_v2' => $this->vec(8)])->save();
        $added = DetectedFace::create(['media_id' => $media->id, 'bounding_box' => ['x' => 18, 'y' => 25, 'width' => 8, 'height' => 9],
            'provider' => 'insightface', 'engine' => 'insightface', 'status' => 'unmatched', 'embedding_v2' => $this->vec(1)]);
        $guess = $this->face($media, ['x' => 60, 'y' => 60, 'width' => 5, 'height' => 5], status: 'rejected');
        $guess->forceFill(['engine' => 'insightface-box'])->save();
        $hidden = $this->face($media, ['x' => 80, 'y' => 10, 'width' => 6, 'height' => 6], $lea);
        $hidden->forceFill(['engine' => 'insightface-box'])->save();
        $this->fakeService([], ['embedding' => $this->vec(9), 'age' => null, 'source' => 'crop']);

        $this->artisan('faces:consolidate --dry-run')->assertSuccessful();
        $this->assertSame('insightface-box', $big->fresh()->engine, 'simulation : rien de modifié');

        $this->artisan('faces:consolidate')->assertSuccessful();

        $this->assertSame('insightface', $big->fresh()->engine);
        $this->assertEquals($this->vec(1), $big->fresh()->embedding_v2);
        $this->assertSame(['dismissed', 'merged'], [$added->fresh()->status, $added->fresh()->engine]);
        $this->assertSame(['dismissed', 'faceapi-unconfirmed'], [$guess->fresh()->status, $guess->fresh()->engine]);
        $this->assertSame([$lea->id, 'insightface-crop'], [$hidden->fresh()->person_id, $hidden->fresh()->engine]);
        $this->assertSame(4, $media->detectedFaces()->count(), 'rien de supprimé');
    }

    public function test_auto_match_requires_strict_distance_and_margin(): void
    {
        $lea = Person::create(['user_id' => $this->user->id, 'name' => 'Léa']);
        $tom = Person::create(['user_id' => $this->user->id, 'name' => 'Tom']);
        $this->face($this->photo(), ['x' => 1, 'y' => 1, 'width' => 5, 'height' => 5], $lea, v2: $this->vec(1));
        $this->face($this->photo(), ['x' => 1, 'y' => 1, 'width' => 5, 'height' => 5], $tom, v2: $this->vec(2));

        $matcher = app(FaceMatcher::class);

        // Très proche de Léa, loin de Tom → associé.
        $clear = $this->face($this->photo(), ['x' => 1, 'y' => 1, 'width' => 5, 'height' => 5], v2: $this->vec(1, 0.2));
        $this->assertSame($lea->id, $matcher->autoMatch($clear, $this->user->id)['person']['id']);
        $this->assertNull($clear->fresh()->verified_at, 'automatique : jamais « vérifié »');

        // À mi-chemin entre Léa et Tom → pas d'association (marge insuffisante).
        $mid = array_map(fn ($a, $b) => ($a + $b) / sqrt(2), $this->vec(1), $this->vec(2));
        $ambiguous = $this->face($this->photo(), ['x' => 1, 'y' => 1, 'width' => 5, 'height' => 5], v2: $mid);
        $this->assertNull($matcher->autoMatch($ambiguous, $this->user->id));
    }

    public function test_server_analysis_endpoint_and_drawn_face(): void
    {
        $media = $this->photo();
        $this->fakeService(
            [$this->detection([100, 100, 200, 220], $this->vec(1))],
            ['embedding' => $this->vec(7), 'age' => 40, 'source' => 'detected'],
        );

        $this->actingAs($this->user)->postJson("/vision/media/{$media->id}/analyze")->assertOk()->assertJsonCount(1);

        $this->actingAs($this->user)->postJson("/vision/media/{$media->id}/faces/add", [
            'bounding_box' => ['x' => 60, 'y' => 60, 'width' => 10, 'height' => 10],
        ])->assertCreated();
        $drawn = $media->detectedFaces()->where('provider', 'manual')->sole();
        $this->assertCount(512, $drawn->embedding_v2);
        $this->assertSame(40, $drawn->age_estimate);
    }

    public function test_admin_force_rebuild_keeps_validated_and_cleans_the_rest(): void
    {
        $admin = User::factory()->admin()->create();
        $lea = Person::create(['user_id' => $this->user->id, 'name' => 'Léa']);
        $media = $this->photo();
        $known = $this->face($media, ['x' => 10, 'y' => 10, 'width' => 30, 'height' => 40], $lea);
        $guest = $this->face($media, ['x' => 60, 'y' => 10, 'width' => 10, 'height' => 12], status: 'rejected');
        $junk = $this->face($media, ['x' => 80, 'y' => 80, 'width' => 5, 'height' => 5]);
        // Noms hors de toute détection : validé par un humain → gardé ;
        // posé par l'ancien automatique → effacé et retiré de la photo.
        $tom = Person::create(['user_id' => $this->user->id, 'name' => 'Tom']);
        $zoe = Person::create(['user_id' => $this->user->id, 'name' => 'Zoé']);
        $validated = $this->face($media, ['x' => 1, 'y' => 60, 'width' => 4, 'height' => 5], $tom);
        $validated->forceFill(['verified_at' => now()])->save();
        $autoGuess = $this->face($media, ['x' => 50, 'y' => 60, 'width' => 4, 'height' => 5], $zoe);
        $fromFace = ['face_coordinates' => '{"x":1}'];
        $media->people()->syncWithoutDetaching([$lea->id => $fromFace, $tom->id => $fromFace, $zoe->id => $fromFace]);
        $this->fakeService([
            $this->detection([180, 250, 260, 340], $this->vec(1)),   // dans le cadre de Léa
            $this->detection([610, 110, 690, 210], $this->vec(2)),   // l'invité
            $this->detection([400, 400, 470, 480], $this->vec(3)),   // nouveau visage
        ]);

        $this->actingAs($this->user)->postJson("/vision/media/{$media->id}/analyze", ['force' => true])->assertForbidden();
        $this->actingAs($admin)->postJson("/vision/media/{$media->id}/analyze", ['force' => true])->assertOk();

        $this->assertSame($lea->id, $known->fresh()->person_id);
        $this->assertEqualsWithDelta(18.0, $known->fresh()->bounding_box['x'], 0.01, 'nouveau cadre');
        $this->assertSame('rejected', $guest->fresh()->status);
        $this->assertNull($junk->fresh(), 'cadre non identifié effacé');
        $this->assertSame($tom->id, $validated->fresh()?->person_id, 'validé par un humain : gardé');
        $this->assertNull($autoGuess->fresh(), 'ancien nom automatique sans visage : effacé');
        $this->assertEqualsCanonicalizing([$lea->id, $tom->id], $media->people()->pluck('people.id')->all());
        $this->assertSame(4, $media->detectedFaces()->count());
    }

    public function test_hd_recheck_keeps_confirmed_names_and_reidentifies_the_rest(): void
    {
        $lea = Person::create(['user_id' => $this->user->id, 'name' => 'Léa']);
        $tom = Person::create(['user_id' => $this->user->id, 'name' => 'Tom']);
        foreach ([0.0, 0.1, 0.2] as $n) {
            $this->face($this->photo(), ['x' => 1, 'y' => 1, 'width' => 5, 'height' => 5], $tom, v2: $this->vec(2, $n));
        }
        $this->face($this->photo(), ['x' => 1, 'y' => 1, 'width' => 5, 'height' => 5], $lea, v2: $this->vec(1));
        $media = $this->photo();
        $box = fn (int $x) => ['x' => $x, 'y' => 10, 'width' => 10, 'height' => 10];
        $tomA = $this->face($media, $box(10), $tom);
        $tomB = $this->face($media, $box(30), $tom);      // Tom une 2e fois : moins ressemblant
        $leaWrong = $this->face($media, $box(50), $lea);  // ressemble à Tom, pas à Léa
        $leaHuman = $this->face($media, $box(70), $lea);
        $leaHuman->forceFill(['verified_at' => now()])->save();
        $this->fakeService([
            $this->detection([100, 100, 200, 200], $this->vec(2, 0.1)),
            $this->detection([300, 100, 400, 200], $this->vec(2, 0.35)),
            $this->detection([500, 100, 600, 200], $this->vec(2, 0.05)),
            $this->detection([700, 100, 800, 200], $this->vec(2, 0.3)),
        ]);

        $stats = app(\App\Services\Vision\FaceReconciler::class)
            ->rebuild($media, app(\App\Services\Vision\FaceEngine::class)->analyze($media), recheck: true);

        $this->assertSame(2, $stats['rechecked']);
        $this->assertSame($tom->id, $tomA->fresh()->person_id, 'confirmé par la reconnaissance');
        $this->assertNull($tomB->fresh()->person_id, 'doublon moins ressemblant');
        $this->assertSame('unmatched', $leaWrong->fresh()->status);
        $this->assertNull($leaWrong->fresh()->person_id);
        $this->assertSame($lea->id, $leaHuman->fresh()->person_id, 'validé par un humain : intouchable');
        $this->assertEqualsWithDelta(70.0, $leaHuman->fresh()->bounding_box['x'], 0.01);
    }

    public function test_server_analysis_is_refused_with_the_browser_engine(): void
    {
        config(['memorylane.faces.engine' => 'faceapi']);
        $this->actingAs($this->user)->postJson("/vision/media/{$this->photo()->id}/analyze")->assertStatus(409);
    }

    public function test_human_match_is_verified_and_excluded_from_review(): void
    {
        $lea = Person::create(['user_id' => $this->user->id, 'name' => 'Léa']);
        $tom = Person::create(['user_id' => $this->user->id, 'name' => 'Tom']);
        foreach ([0.0, 0.1, 0.2] as $n) {
            $this->face($this->photo(), ['x' => 1, 'y' => 1, 'width' => 5, 'height' => 5], $tom, v2: $this->vec(2, $n));
        }
        $this->face($this->photo(), ['x' => 1, 'y' => 1, 'width' => 5, 'height' => 5], $lea, v2: $this->vec(1));
        $this->face($this->photo(), ['x' => 1, 'y' => 1, 'width' => 5, 'height' => 5], $lea, v2: $this->vec(1, 0.1));
        // Identifié comme Léa mais ressemble à Tom.
        $suspect = $this->face($this->photo(), ['x' => 1, 'y' => 1, 'width' => 5, 'height' => 5], $lea, v2: $this->vec(2, 0.05));

        $this->assertSame('other', app(DisputedFaceResolver::class)->analysis()[$suspect->id]['reason']);
        $this->assertSame($tom->id, app(DisputedFaceResolver::class)->analysis()[$suspect->id]['best_person']);

        $this->actingAs($this->user)->postJson("/vision/faces/{$suspect->id}/match", ['person_id' => $lea->id])->assertOk();
        $this->assertNotNull($suspect->fresh()->verified_at);
        app()->forgetScopedInstances();
        $this->assertArrayNotHasKey($suspect->id, app(DisputedFaceResolver::class)->analysis());
    }

    public function test_admin_applies_suggestions_in_bulk(): void
    {
        $admin = User::factory()->admin()->create();
        $lea = Person::create(['user_id' => $this->user->id, 'name' => 'Léa']);
        $tom = Person::create(['user_id' => $this->user->id, 'name' => 'Tom']);
        foreach ([0.0, 0.1, 0.2] as $n) {
            $this->face($this->photo(), ['x' => 1, 'y' => 1, 'width' => 5, 'height' => 5], $tom, v2: $this->vec(2, $n));
        }
        $this->face($this->photo(), ['x' => 1, 'y' => 1, 'width' => 5, 'height' => 5], $lea, v2: $this->vec(1));
        $this->face($this->photo(), ['x' => 1, 'y' => 1, 'width' => 5, 'height' => 5], $lea, v2: $this->vec(1, 0.1));
        $suspect = $this->face($this->photo(), ['x' => 1, 'y' => 1, 'width' => 5, 'height' => 5], $lea, v2: $this->vec(2, 0.05));
        // Même suggestion, mais Tom est déjà sur cette photo : refusé (doublon).
        $crowded = $this->photo();
        $this->face($crowded, ['x' => 50, 'y' => 1, 'width' => 5, 'height' => 5], $tom, v2: $this->vec(2, 0.15));
        $blocked = $this->face($crowded, ['x' => 1, 'y' => 1, 'width' => 5, 'height' => 5], $lea, v2: $this->vec(2, 0.06));

        $this->assertSame($tom->id, app(DisputedFaceResolver::class)->analysis()[$blocked->id]['best_person'] ?? null);

        $this->actingAs($admin);
        \Livewire\Livewire::test(\App\Filament\Resources\DisputedFaceResource\Pages\ListDisputedFaces::class)
            ->callTableBulkAction('replaceAll', [$suspect, $blocked]);

        $this->assertSame($tom->id, $suspect->fresh()->person_id);
        $this->assertNotNull($suspect->fresh()->verified_at);
        $this->assertSame($lea->id, $blocked->fresh()->person_id, 'doublon refusé, rien ne change');
    }

    public function test_tag_only_keeps_the_person_on_the_photo_without_the_frame(): void
    {
        $lea = Person::create(['user_id' => $this->user->id, 'name' => 'Léa']);
        $media = $this->photo();
        $back = $this->face($media, ['x' => 1, 'y' => 1, 'width' => 5, 'height' => 5], v2: $this->vec(1));
        app(FaceMatcher::class)->applyMatch($back, $lea->id);

        app(FaceMatcher::class)->keepTagOnly($back->fresh());

        $this->assertSame('dismissed', $back->fresh()->status);
        $this->assertNull($back->fresh()->person_id, 'plus une référence pour la reconnaissance');
        $this->assertTrue($media->people()->whereKey($lea->id)->exists(), 'reste taguée sur la photo');

        // Un autre visage d'elle retiré ensuite : le tag sans cadre survit.
        $other = $this->face($media, ['x' => 50, 'y' => 1, 'width' => 5, 'height' => 5], v2: $this->vec(1, 0.1));
        app(FaceMatcher::class)->applyMatch($other, $lea->id, allowDuplicate: true);
        $media->people()->updateExistingPivot($lea->id, ['face_coordinates' => null]);
        app(FaceMatcher::class)->disassociate($other->fresh());
        $this->assertTrue($media->people()->whereKey($lea->id)->exists());
    }

    public function test_bulk_remove_hides_frames_without_a_clear_face(): void
    {
        $admin = User::factory()->admin()->create();
        $lea = Person::create(['user_id' => $this->user->id, 'name' => 'Léa']);
        $tom = Person::create(['user_id' => $this->user->id, 'name' => 'Tom']);
        foreach ([0.0, 0.1, 0.2] as $n) {
            $this->face($this->photo(), ['x' => 1, 'y' => 1, 'width' => 5, 'height' => 5], $tom, v2: $this->vec(2, $n));
        }
        $this->face($this->photo(), ['x' => 1, 'y' => 1, 'width' => 5, 'height' => 5], $lea, v2: $this->vec(1));
        $this->face($this->photo(), ['x' => 1, 'y' => 1, 'width' => 5, 'height' => 5], $lea, v2: $this->vec(1, 0.1));
        $suspect = $this->face($this->photo(), ['x' => 1, 'y' => 1, 'width' => 5, 'height' => 5], $lea, v2: $this->vec(2, 0.05));
        $blurry = $this->face($this->photo(), ['x' => 1, 'y' => 1, 'width' => 5, 'height' => 5], $lea, v2: $this->vec(1, 0.05));
        $blurry->forceFill(['engine' => 'insightface-crop'])->save();
        $analysis = app(DisputedFaceResolver::class)->analysis();
        $this->assertSame(['other', 'noface'], [$analysis[$suspect->id]['reason'] ?? null, $analysis[$blurry->id]['reason'] ?? null]);

        $this->actingAs($admin);
        \Livewire\Livewire::test(\App\Filament\Resources\DisputedFaceResource\Pages\ListDisputedFaces::class)
            ->callTableBulkAction('removeAll', [$suspect, $blurry]);

        $this->assertSame('rejected', $suspect->fresh()->status, 'visage net : reste « inconnu »');
        $this->assertSame('dismissed', $blurry->fresh()->status, 'pas de visage net : cadre masqué');
        $this->assertNull($blurry->fresh()->person_id);
    }

    public function test_admin_identifies_in_bulk_by_person(): void
    {
        $admin = User::factory()->admin()->create();
        $tom = Person::create(['user_id' => $this->user->id, 'name' => 'Tom', 'birth_date' => '2000-01-01']);
        foreach ([0.0, 0.1, 0.2] as $n) {
            $this->face($this->photo(), ['x' => 1, 'y' => 1, 'width' => 5, 'height' => 5], $tom, v2: $this->vec(2, $n));
        }
        $box = ['x' => 1, 'y' => 1, 'width' => 5, 'height' => 5];
        // Créés du moins au plus ressemblant : l'ordre des identifiants (par
        // date de création) ne doit pas masquer le tri par ressemblance.
        $farthest = $this->face($this->photo(), $box, v2: $this->vec(2, 0.5));
        $farther = $this->face($this->photo(), $box, v2: $this->vec(2, 0.4));
        $close = $this->face($this->photo(), $box, v2: $this->vec(2, 0.05));
        $other = $this->face($this->photo(), $box, v2: $this->vec(1));                 // quelqu'un d'autre
        $withTom = $this->photo();
        $this->face($withTom, ['x' => 50, 'y' => 1, 'width' => 5, 'height' => 5], $tom, v2: $this->vec(2, 0.15));
        $sameFrame = $this->face($withTom, $box, v2: $this->vec(2, 0.06));            // Tom déjà sur la photo
        $tooEarly = $this->face($this->photo('1990-06-01'), $box, v2: $this->vec(2, 0.05)); // avant sa naissance

        $this->assertSame([$close->id, $farther->id, $farthest->id], array_keys(app(\App\Services\Vision\PersonFaceCandidates::class)->for($tom->id)));

        $this->actingAs($admin);
        $page = \Livewire\Livewire::test(\App\Filament\Resources\PersonFaceResource\Pages\ListPersonFaces::class)
            ->assertCountTableRecords(0)
            ->filterTable('person', $tom->id)
            ->assertCanSeeTableRecords([$close, $farther, $farthest], inOrder: true)
            ->assertCanNotSeeTableRecords([$other, $sameFrame, $tooEarly])
            ->callTableBulkAction('confirmAll', [$close, $farther]);

        $this->assertSame([$tom->id, $tom->id], [$close->fresh()->person_id, $farther->fresh()->person_id]);
        $this->assertNotNull($close->fresh()->verified_at, 'validé par un humain');
    }

    public function test_people_are_sorted_by_number_of_suggestions(): void
    {
        $admin = User::factory()->admin()->create();
        $tom = Person::create(['user_id' => $this->user->id, 'name' => 'Tom']);
        $lea = Person::create(['user_id' => $this->user->id, 'name' => 'Léa']);
        Person::create(['user_id' => $this->user->id, 'name' => 'Aline']);
        $box = ['x' => 1, 'y' => 1, 'width' => 5, 'height' => 5];
        foreach ([0.0, 0.1, 0.2] as $n) {
            $this->face($this->photo(), $box, $tom, v2: $this->vec(2, $n));
        }
        $this->face($this->photo(), $box, $lea, v2: $this->vec(1));
        $this->face($this->photo(), $box, $lea, v2: $this->vec(1, 0.1));
        $this->face($this->photo(), $box, v2: $this->vec(2, 0.05));
        $this->face($this->photo(), $box, v2: $this->vec(2, 0.3));
        $this->face($this->photo(), $box, v2: $this->vec(1, 0.05));

        $live = app(\App\Services\Vision\PersonFaceCandidates::class)->for($tom->id);
        $state = app(\App\Services\Vision\PersonFaceCandidates::class)->counts();

        $this->assertTrue($state['fresh']);
        $this->assertSame([$tom->id => 2, $lea->id => 1], $state['counts']);
        $this->assertSame($live, app(\App\Services\Vision\PersonFaceCandidates::class)->for($tom->id), 'calcul global = calcul par personne');

        $this->actingAs($admin);
        \Livewire\Livewire::test(\App\Filament\Resources\PersonFaceResource\Pages\ListPersonFaces::class)
            ->assertSeeInOrder(['Tom (2)', 'Léa (1)', 'Aline']);
    }

    public function test_row_actions_confirm_and_not_this_person(): void
    {
        // Boutons d'une ligne (pas l'action groupée) : ils passaient
        // collect([$record]) à des aides typées « collection Eloquent » (erreur 500).
        $admin = User::factory()->admin()->create();
        $tom = Person::create(['user_id' => $this->user->id, 'name' => 'Tom']);
        $box = ['x' => 1, 'y' => 1, 'width' => 5, 'height' => 5];
        foreach ([0.0, 0.1, 0.2] as $n) {
            $this->face($this->photo(), $box, $tom, v2: $this->vec(2, $n));
        }
        $yes = $this->face($this->photo(), $box, v2: $this->vec(2, 0.05));
        $no = $this->face($this->photo(), $box, v2: $this->vec(2, 0.06));

        $this->actingAs($admin);
        \Livewire\Livewire::test(\App\Filament\Resources\PersonFaceResource\Pages\ListPersonFaces::class)
            ->filterTable('person', $tom->id)
            ->callTableAction('confirm', $yes)
            ->assertHasNoTableActionErrors()
            ->callTableAction('notThem', $no)
            ->assertHasNoTableActionErrors();

        $this->assertSame($tom->id, $yes->fresh()->person_id);
        $this->assertSame('unmatched', $no->fresh()->status);
        $this->assertArrayNotHasKey($no->id, app(\App\Services\Vision\PersonFaceCandidates::class)->for($tom->id));
    }

    public function test_not_this_person_is_never_proposed_again(): void
    {
        $admin = User::factory()->admin()->create();
        $tom = Person::create(['user_id' => $this->user->id, 'name' => 'Tom']);
        $box = ['x' => 1, 'y' => 1, 'width' => 5, 'height' => 5];
        foreach ([0.0, 0.1, 0.2] as $n) {
            $this->face($this->photo(), $box, $tom, v2: $this->vec(2, $n));
        }
        $lookalike = $this->face($this->photo(), $box, v2: $this->vec(2, 0.05));   // sosie de Tom
        $other = $this->face($this->photo(), $box, v2: $this->vec(2, 0.3));
        $matcher = app(FaceMatcher::class);
        $candidates = app(\App\Services\Vision\PersonFaceCandidates::class);
        $this->assertArrayHasKey($lookalike->id, $candidates->for($tom->id));

        $this->actingAs($admin);
        \Livewire\Livewire::test(\App\Filament\Resources\PersonFaceResource\Pages\ListPersonFaces::class)
            ->filterTable('person', $tom->id)
            ->callTableBulkAction('notThemAll', [$lookalike])
            ->assertCanNotSeeTableRecords([$lookalike])
            ->assertCanSeeTableRecords([$other]);

        $this->assertSame('unmatched', $lookalike->fresh()->status, 'reste à identifier');
        $this->assertArrayNotHasKey($lookalike->id, $candidates->for($tom->id));
        $this->assertSame(1, $candidates->counts()['counts'][$tom->id] ?? 0, 'plus compté pour Tom');
        $this->assertSame([], $matcher->rankedCandidates($lookalike->fresh(), $matcher->matchThreshold(), $this->user->id), 'plus suggéré');
        $this->assertNull($matcher->autoMatch($lookalike->fresh(), $this->user->id), 'jamais associé automatiquement');

        // Identifié comme Tom quand même (à la main) : l'exclusion tombe.
        $matcher->applyMatch($lookalike->fresh(), $tom->id, verified: true);
        $this->assertSame([], $matcher->excludedPeople($lookalike->fresh()));
    }

    public function test_suggestions_skip_people_unborn_or_of_an_incompatible_age(): void
    {
        $box = ['x' => 1, 'y' => 1, 'width' => 5, 'height' => 5];
        $aged = function (Media $media, ?Person $person, array $v2, ?int $age) use ($box) {
            $face = $this->face($media, $box, $person, v2: $v2);
            $face->forceFill(['age_estimate' => $age])->save();

            return $face;
        };
        // Sans date de naissance, mais l'âge apparent sur 5 photos de 2020 la situe vers 1990.
        $pascale = Person::create(['user_id' => $this->user->id, 'name' => 'Pascale']);
        foreach ([0.0, 0.05, 0.1, 0.15, 0.2] as $n) {
            $aged($this->photo('2020-06-01'), $pascale, $this->vec(2, $n), 30);
        }
        $vincent = Person::create(['user_id' => $this->user->id, 'name' => 'Vincent', 'birth_date' => '1958-04-01']);
        foreach ([0.0, 0.1] as $n) {
            $aged($this->photo('2020-06-01'), $vincent, $this->vec(3, $n), 62);
        }
        $marianne = Person::create(['user_id' => $this->user->id, 'name' => 'Marianne', 'birth_date' => '1931-03-01']);
        foreach ([0.0, 0.1] as $n) {
            $aged($this->photo('2000-06-01'), $marianne, $this->vec(4, $n), 69);
        }
        $this->assertSame(1990, app(\App\Services\Vision\FacePlausibility::class)->birthEstimates()[$pascale->id] ?? null);

        $film = $this->photo('1957-08-01');
        $suggested = fn (DetectedFace $f) => collect(app(FaceMatcher::class)->rankedCandidates($f, app(FaceMatcher::class)->matchThreshold(), $this->user->id))
            ->pluck('person.name')->all();

        $this->assertSame([], $suggested($aged($film, null, $this->vec(2, 0.07), 40)), 'Pascale : pas encore née (estimation)');
        $vincentLike = $aged($film, null, $this->vec(3, 0.05), 55);
        $this->assertSame([], $suggested($vincentLike), 'Vincent : né 8 mois après la vidéo');
        $this->assertSame(['Marianne'], $suggested($aged($film, null, $this->vec(4, 0.05), 40)), '26 ans à l\'époque : plausible');

        // Toujours possible à la main (marge d'un an pour les dates approximatives).
        app(FaceMatcher::class)->applyMatch($vincentLike, $vincent->id);
        $this->assertSame($vincent->id, $vincentLike->fresh()->person_id);

        // Sur une photo récente, Pascale redevient une suggestion.
        $this->assertContains('Pascale', $suggested($aged($this->photo('2021-06-01'), null, $this->vec(2, 0.07), 31)));
    }

    public function test_quest_stranger_keeps_the_face_visible_not_face_hides_it(): void
    {
        config(['memorylane.faces.engine' => 'faceapi']);
        $media = $this->photo();
        $guest = $this->face($media, ['x' => 10, 'y' => 10, 'width' => 8, 'height' => 8]);
        $wall = $this->face($media, ['x' => 50, 'y' => 50, 'width' => 8, 'height' => 8]);
        $answer = fn (DetectedFace $f, string $reason) => $this->actingAs($this->user)->postJson('/quests/answer', [
            'question_type' => 'face_identify', 'subject_id' => $f->id, 'answer_kind' => 'no', 'payload' => ['reason' => $reason],
        ]);

        $answer($guest, 'stranger')->assertSuccessful();
        $answer($wall, 'not_face')->assertSuccessful();

        $this->assertSame('rejected', $guest->fresh()->status, 'invité : visible, hors quêtes');
        $this->assertSame('dismissed', $wall->fresh()->status, 'fausse détection : masquée');
    }

    public function test_reanalyze_command_targets_media_not_yet_analyzed(): void
    {
        Queue::fake();
        $todo = $this->photo();
        $this->face($todo, ['x' => 1, 'y' => 1, 'width' => 5, 'height' => 5]);
        $done = $this->photo();
        $this->face($done, ['x' => 1, 'y' => 1, 'width' => 5, 'height' => 5], v2: $this->vec(1));
        $done->metadata()->create(['vision_provider' => 'insightface', 'vision_status' => 'completed']);

        $this->artisan('faces:reanalyze')->assertSuccessful();

        Queue::assertPushed(AnalyzeFaces::class, fn ($job) => $job->media->is($todo));
        Queue::assertNotPushed(AnalyzeFaces::class, fn ($job) => $job->media->is($done));
    }
}
