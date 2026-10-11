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
        $this->assertSame('insightface-box', $missed->fresh()->engine, 'visage non retrouvé conservé et complété');

        $this->assertSame(4, $media->detectedFaces()->count(), '3 existants + 1 nouveau, rien de supprimé');
        $new = $media->detectedFaces()->where('provider', 'insightface')->sole();
        $this->assertSame('unmatched', $new->status);
        $this->assertEqualsWithDelta(50.0, $new->bounding_box['x'], 0.01);

        // Deuxième passe : aucun doublon.
        AnalyzeFaces::dispatchSync($media);
        $this->assertSame(4, $media->detectedFaces()->count());
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
