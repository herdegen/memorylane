<?php

namespace Tests\Feature;

use App\Models\Media;
use App\Models\Person;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Vidéo découpée en clips : elle disparaît des listes (fiches, galeries,
 * recherche…) au profit de ses clips, reste ouvrable depuis eux, et sa page
 * montre les personnes de tous ses clips.
 */
class ClipSourceVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private Person $christine;
    private Person $matthieu;
    private Media $source;
    private Media $clip;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->owner = User::factory()->create();
        $this->christine = Person::factory()->create(['user_id' => $this->owner->id, 'name' => 'Christine HERDEGEN']);
        $this->matthieu = Person::factory()->create(['user_id' => $this->owner->id, 'name' => 'Matthieu HERDEGEN']);

        $this->source = Media::factory()->video()->create(['user_id' => $this->owner->id, 'is_source' => true]);
        $this->source->people()->attach($this->christine->id);
        $this->clip = Media::factory()->video()->create(['user_id' => $this->owner->id, 'source_media_id' => $this->source->id, 'clip_start' => 0, 'clip_end' => 150]);
        $this->clip->people()->attach([$this->christine->id, $this->matthieu->id]);
        $other = Media::factory()->video()->create(['user_id' => $this->owner->id, 'source_media_id' => $this->source->id, 'clip_start' => 150, 'clip_end' => 300]);
        $other->people()->attach($this->christine->id);
    }

    public function test_l_original_n_est_plus_dans_les_listes(): void
    {
        $listed = Media::inContext($this->owner)->pluck('id');
        $this->assertNotContains($this->source->id, $listed);
        $this->assertContains($this->clip->id, $listed);

        $this->actingAs($this->owner)->get("/people/{$this->christine->id}")
            ->assertInertia(fn ($page) => $page->where('media', fn ($media) => collect($media)->pluck('id')->doesntContain($this->source->id)
                && collect($media)->pluck('id')->contains($this->clip->id)));
    }

    public function test_l_original_reste_ouvrable_et_montre_les_personnes_de_ses_clips(): void
    {
        $this->actingAs($this->owner)->get("/media/{$this->source->id}")->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('clipPeople.0.name', 'Christine HERDEGEN')
                ->where('clipPeople.0.clips_count', 2)
                ->where('clipPeople.1.name', 'Matthieu HERDEGEN')
                ->where('clipPeople.1.clips_count', 1));

        // Le clip mène à sa vidéo complète.
        $this->actingAs($this->owner)->get("/media/{$this->clip->id}")
            ->assertInertia(fn ($page) => $page->where('media.source_media.id', $this->source->id)->where('clipPeople', []));
    }
}
