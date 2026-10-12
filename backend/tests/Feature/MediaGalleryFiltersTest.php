<?php

namespace Tests\Feature;

use App\Models\DetectedFace;
use App\Models\Media;
use App\Models\Person;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tri et filtres de la galerie « Mes photos » : période, sans date,
 * personnes (ensemble), aucune personne, visages à identifier.
 */
class MediaGalleryFiltersTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
    }

    protected function photo(array $attributes = []): Media
    {
        return Media::factory()->photo()->create(array_merge([
            'user_id' => $this->user->id,
            'taken_at' => '2015-06-01 12:00:00',
        ], $attributes));
    }

    /** @return array<int,string> ids renvoyés, dans l'ordre */
    protected function galleryIds(string $query = ''): array
    {
        return collect($this->actingAs($this->user)->getJson('/media?' . $query)->assertOk()->json('data'))
            ->pluck('id')->all();
    }

    protected function face(Media $media, array $attributes): DetectedFace
    {
        return DetectedFace::create(array_merge([
            'media_id' => $media->id,
            'bounding_box' => ['x' => 10, 'y' => 10, 'width' => 20, 'height' => 20],
            'confidence' => 0.9,
            'provider' => 'insightface',
            'status' => 'unmatched',
        ], $attributes));
    }

    public function test_sorts_by_taken_date_both_ways_with_undated_last(): void
    {
        $old = $this->photo(['taken_at' => '1990-01-01']);
        $new = $this->photo(['taken_at' => '2020-01-01']);
        $undated = $this->photo(['taken_at' => null]);

        $this->assertSame([$new->id, $old->id, $undated->id], $this->galleryIds());
        $this->assertSame([$old->id, $new->id, $undated->id], $this->galleryIds('sort=oldest'));
    }

    public function test_sorts_by_upload_date(): void
    {
        $first = $this->photo(['taken_at' => '2020-01-01', 'uploaded_at' => now()->subDays(3)]);
        $last = $this->photo(['taken_at' => '1990-01-01', 'uploaded_at' => now()]);

        $this->assertSame([$last->id, $first->id], $this->galleryIds('sort=added'));
    }

    public function test_filters_by_year_range_inclusive(): void
    {
        $this->photo(['taken_at' => '1999-12-31 23:00:00']);
        $in1 = $this->photo(['taken_at' => '2000-01-01 00:00:00']);
        $in2 = $this->photo(['taken_at' => '2003-12-31 20:00:00']);
        $this->photo(['taken_at' => '2004-01-01 00:00:00']);
        $this->photo(['taken_at' => null]);

        $ids = $this->galleryIds('year_from=2000&year_to=2003');
        $this->assertEqualsCanonicalizing([$in1->id, $in2->id], $ids);

        // Bornes absurdes ignorées plutôt qu'erreur.
        $this->assertCount(5, $this->galleryIds('year_from=abc&year_to=99999'));
    }

    public function test_filters_undated(): void
    {
        $this->photo(['taken_at' => '2010-01-01']);
        $undated = $this->photo(['taken_at' => null]);

        $this->assertSame([$undated->id], $this->galleryIds('undated=1'));
    }

    public function test_filters_people_together(): void
    {
        $alice = Person::factory()->create(['user_id' => $this->user->id]);
        $bob = Person::factory()->create(['user_id' => $this->user->id]);

        $both = $this->photo();
        $both->people()->attach([$alice->id, $bob->id]);
        $onlyAlice = $this->photo();
        $onlyAlice->people()->attach($alice->id);
        $this->photo();

        $this->assertEqualsCanonicalizing([$both->id, $onlyAlice->id], $this->galleryIds("people[]={$alice->id}"));
        $this->assertSame([$both->id], $this->galleryIds("people[]={$alice->id}&people[]={$bob->id}"));
        // Identifiant invalide ignoré.
        $this->assertCount(3, $this->galleryIds('people[]=pas-un-uuid'));
    }

    public function test_filters_media_without_people_excluding_documents(): void
    {
        $person = Person::factory()->create(['user_id' => $this->user->id]);
        $tagged = $this->photo();
        $tagged->people()->attach($person->id);
        $nobody = $this->photo();
        Media::factory()->document()->create(['user_id' => $this->user->id]);

        $this->assertSame([$nobody->id], $this->galleryIds('people_mode=none'));
    }

    public function test_filters_media_with_faces_to_identify(): void
    {
        $person = Person::factory()->create(['user_id' => $this->user->id]);

        $toIdentify = $this->photo();
        $this->face($toIdentify, ['status' => 'matched', 'person_id' => $person->id]);
        $this->face($toIdentify, ['status' => 'unmatched']);

        $allDone = $this->photo();
        $this->face($allDone, ['status' => 'matched', 'person_id' => $person->id]);
        $this->face($allDone, ['status' => 'rejected']);   // inconnu, déjà trié
        $this->face($allDone, ['status' => 'dismissed']);  // faux cadre

        $this->assertSame([$toIdentify->id], $this->galleryIds('people_mode=unidentified'));
    }

    public function test_select_all_ids_follow_the_new_filters(): void
    {
        $undated = $this->photo(['taken_at' => null]);
        $this->photo();

        $this->actingAs($this->user)->getJson('/media/ids?undated=1')
            ->assertOk()
            ->assertExactJson(['ids' => [$undated->id]]);
    }

    public function test_page_exposes_years_counts_and_filtered_people_names(): void
    {
        $person = Person::factory()->create(['user_id' => $this->user->id, 'name' => 'Marie Curie']);
        $this->photo(['taken_at' => '1998-05-05'])->people()->attach($person->id);
        $this->photo(['taken_at' => '2012-05-05']);
        $toIdentify = $this->photo(['taken_at' => null]);
        $this->face($toIdentify, ['status' => 'unmatched']);

        $this->actingAs($this->user)
            ->get("/media?people[]={$person->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Media/Index', false)
                ->where('years', [2012, 1998])
                ->where('counts.undated', 1)
                ->where('counts.no_people', 2)
                ->where('counts.unidentified', 1)
                ->where('filterPeople.0.name', 'Marie Curie')
            );
    }

    public function test_people_facets_list_people_on_own_media_with_counts(): void
    {
        $other = User::factory()->create();
        $often = Person::factory()->create(['user_id' => $this->user->id, 'name' => 'Souvent']);
        $once = Person::factory()->create(['user_id' => $this->user->id, 'name' => 'Une fois']);
        $elsewhere = Person::factory()->create(['user_id' => $this->user->id, 'name' => 'Ailleurs']);

        $this->photo()->people()->attach([$often->id, $once->id]);
        $this->photo()->people()->attach($often->id);
        Media::factory()->photo()->create(['user_id' => $other->id])->people()->attach($elsewhere->id);

        $this->actingAs($this->user)->getJson('/media/people')
            ->assertOk()
            ->assertJsonCount(2, 'people')
            ->assertJsonPath('people.0.name', 'Souvent')
            ->assertJsonPath('people.0.media_count', 2)
            ->assertJsonPath('people.1.name', 'Une fois')
            ->assertJsonPath('people.1.media_count', 1);
    }
}
