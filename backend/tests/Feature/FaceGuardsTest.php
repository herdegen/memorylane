<?php

namespace Tests\Feature;

use App\Filament\Resources\DuplicateFaceResource\Pages\ListDuplicateFaces;
use App\Models\DetectedFace;
use App\Models\Media;
use App\Models\Person;
use App\Models\User;
use App\Services\Vision\DuplicateFaceResolver;
use App\Services\Vision\FaceMatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Garde-fous des identifications : personne pas encore née / décédée à la
 * date de la photo, doublons sur une même photo, doutes (bébé, parenté).
 */
class FaceGuardsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        config(['memorylane.faces.auto_match' => true]);
        $this->user = User::factory()->create();
    }

    private function photo(string $takenAt): Media
    {
        return Media::factory()->create(['user_id' => $this->user->id, 'type' => 'photo', 'taken_at' => $takenAt]);
    }

    private function face(Media $media, float $seed, ?Person $person = null): DetectedFace
    {
        $vec = array_fill(0, 128, 0.0);
        $vec[0] = $seed;

        return DetectedFace::create([
            'media_id' => $media->id,
            'bounding_box' => ['x' => 1, 'y' => 1, 'width' => 1, 'height' => 1],
            'provider' => 'faceapi',
            'status' => $person ? 'matched' : 'unmatched',
            'person_id' => $person?->id,
            'embedding' => $vec,
        ]);
    }

    private function person(array $attrs = []): Person
    {
        return Person::create(['user_id' => $this->user->id, 'name' => 'Fiche '.uniqid(), ...$attrs]);
    }

    public function test_cannot_identify_someone_not_yet_born(): void
    {
        $face = $this->face($this->photo('1917-06-01'), 0.1);
        $person = $this->person(['name' => 'Léa', 'gender' => 'F', 'birth_date' => '1960-04-02']);

        $this->actingAs($this->user)->postJson("/vision/faces/{$face->id}/match", ['person_id' => $person->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('person_id')
            ->assertJsonFragment(['person_id' => ['Léa est née en 1960 : elle ne peut pas figurer sur une photo de 1917. Si la date de la photo est fausse, corrigez-la d\'abord.']]);

        $this->assertNull($face->fresh()->person_id);
    }

    public function test_cannot_identify_someone_already_dead(): void
    {
        $face = $this->face($this->photo('1990-06-01'), 0.1);
        $person = $this->person(['gender' => 'M', 'birth_date' => '1900-01-01', 'death_date' => '1950-03-03']);

        $this->actingAs($this->user)->postJson("/vision/faces/{$face->id}/match", ['person_id' => $person->id])
            ->assertStatus(422)->assertJsonValidationErrors('person_id');
    }

    public function test_one_year_tolerance_for_approximate_photo_dates(): void
    {
        $face = $this->face($this->photo('1917-01-01'), 0.1);
        $person = $this->person(['birth_date' => '1917-09-15']);

        $this->actingAs($this->user)->postJson("/vision/faces/{$face->id}/match", ['person_id' => $person->id])
            ->assertOk();
    }

    public function test_tagging_a_person_on_a_photo_is_guarded_too(): void
    {
        $media = $this->photo('1917-06-01');
        $person = $this->person(['birth_date' => '1980-01-01']);

        $this->actingAs($this->user)->postJson('/people/attach', ['media_id' => $media->id, 'person_id' => $person->id])
            ->assertStatus(422);
        $this->assertDatabaseMissing('media_person', ['media_id' => $media->id, 'person_id' => $person->id]);
    }

    public function test_same_person_twice_on_a_photo_needs_confirmation(): void
    {
        $media = $this->photo('2020-06-01');
        $person = $this->person();
        $this->face($media, 0.1, $person);
        $second = $this->face($media, 0.2);

        $this->actingAs($this->user)->postJson("/vision/faces/{$second->id}/match", ['person_id' => $person->id])
            ->assertStatus(422)->assertJsonValidationErrors('duplicate');

        $this->actingAs($this->user)->postJson("/vision/faces/{$second->id}/match", ['person_id' => $person->id, 'allow_duplicate' => true])
            ->assertOk();
        $this->assertSame($person->id, $second->fresh()->person_id);
    }

    public function test_suggestions_exclude_the_unborn_and_flag_babies(): void
    {
        $unborn = $this->person(['birth_date' => '2000-01-01']);
        $baby = $this->person(['birth_date' => '1949-05-01']);
        $this->face($this->photo('2010-01-01'), 0.10, $unborn);
        $this->face($this->photo('2010-01-02'), 0.11, $baby);

        $target = $this->face($this->photo('1950-01-01'), 0.105);

        $suggestions = $this->actingAs($this->user)->getJson("/vision/faces/{$target->id}/suggest")
            ->assertOk()->json('suggestions');

        $ids = array_column(array_column($suggestions, 'person'), 'id');
        $this->assertNotContains($unborn->id, $ids);
        $this->assertContains($baby->id, $ids);
        $babyRow = collect($suggestions)->firstWhere('person.id', $baby->id);
        $this->assertSame('baby', $babyRow['doubts'][0]['code']);
    }

    public function test_doubtful_candidate_is_never_auto_matched(): void
    {
        $baby = $this->person(['birth_date' => '1949-05-01']);
        $this->face($this->photo('2010-01-02'), 0.11, $baby);
        $target = $this->face($this->photo('1950-01-01'), 0.110);

        $this->assertNull(app(FaceMatcher::class)->autoMatch($target, $this->user->id));
        $this->assertNull($target->fresh()->person_id);
    }

    public function test_auto_match_is_off_by_default(): void
    {
        config(['memorylane.faces.auto_match' => false]);
        $person = $this->person();
        $this->face($this->photo('2010-01-02'), 0.11, $person);
        $target = $this->face($this->photo('2011-01-01'), 0.110);

        $this->assertNull(app(FaceMatcher::class)->autoMatch($target, $this->user->id));
    }

    public function test_unrelated_candidate_is_flagged_as_distant(): void
    {
        $parent = $this->person();
        $child = $this->person(['father_id' => $parent->id]);
        $stranger = $this->person();
        foreach ([[$child, 0.20], [$stranger, 0.21]] as [$p, $seed]) {
            $this->face($this->photo('2015-01-01'), $seed, $p);
        }

        $media = $this->photo('2016-01-01');
        $this->face($media, 0.5, $parent);          // déjà identifié sur la photo
        $target = $this->face($media, 0.205);

        $rows = collect(app(FaceMatcher::class)->rankedCandidates($target, app(FaceMatcher::class)->matchThreshold(), $this->user->id));
        $this->assertSame([], $rows->firstWhere('person.id', $child->id)['doubts']);
        $this->assertSame('distant', $rows->firstWhere('person.id', $stranger->id)['doubts'][0]['code']);
    }

    public function test_a_friend_is_never_flagged_as_distant_kin(): void
    {
        $parent = $this->person();
        $friend = $this->person(['in_family_directory' => false]);
        $media = $this->photo('2016-01-01');
        $this->face($media, 0.5, $parent);

        // La parenté ne dit rien d'un ami : pas de « pas de lien familial ».
        $this->assertSame([], $this->doubtsOn($media, $friend));
    }

    public function test_friends_on_the_photo_are_not_kin_anchors(): void
    {
        $friend = $this->person(['in_family_directory' => false]);
        $media = $this->photo('2016-01-01');
        $this->face($media, 0.5, $friend);

        $kin = app(\App\Services\Vision\FacePlausibility::class)->kinDistances($media);
        $this->assertArrayNotHasKey($friend->id, $kin ?? []);
    }

    /**
     * Arbre : moi (marié en 2020) ; ma femme et son cousin (via son père et
     * son oncle) ; mon arrière-grand-oncle (côté paternel) et mon cousin par
     * ma mère.
     */
    private function family(): array
    {
        $p = fn (array $a) => $this->person(['birth_date' => '1950-01-01', ...$a]);
        $ggp = $p(['birth_date' => '1890-01-01']);                         // arrière-grand-père
        $ggUncle = $p(['birth_date' => '1895-01-01', 'name' => 'Arrière-grand-oncle']);
        $gp = $p(['birth_date' => '1925-01-01', 'father_id' => $ggp->id]);
        $dad = $p(['father_id' => $gp->id]);
        $momsDad = $p(['birth_date' => '1925-01-01']);
        $mom = $p(['father_id' => $momsDad->id]);
        $me = $p(['birth_date' => '1988-01-01', 'father_id' => $dad->id, 'mother_id' => $mom->id]);
        $momsSister = $p(['father_id' => $momsDad->id]);
        $myCousin = $p(['birth_date' => '1985-01-01', 'mother_id' => $momsSister->id]);
        $wifesGp = $p(['birth_date' => '1925-01-01']);
        $wifesDad = $p(['father_id' => $wifesGp->id]);
        $wife = $p(['birth_date' => '1989-01-01', 'father_id' => $wifesDad->id]);
        $wifesUncle = $p(['father_id' => $wifesGp->id]);
        $wifesCousin = $p(['birth_date' => '1984-01-01', 'father_id' => $wifesUncle->id]);
        // Parents mariés en 1980 ; moi marié en 2020.
        \App\Models\PersonRelationship::create(['person1_id' => $dad->id, 'person2_id' => $mom->id, 'type' => 'spouse', 'start_date' => '1980-06-01']);
        \App\Models\PersonRelationship::create(['person1_id' => $me->id, 'person2_id' => $wife->id, 'type' => 'spouse', 'start_date' => '2020-06-01']);
        // Lien de fratrie de l'arrière-grand-oncle : même père que l'arrière-grand-père.
        $gggp = $p(['birth_date' => '1860-01-01']);
        $ggp->update(['father_id' => $gggp->id]);
        $ggUncle->update(['father_id' => $gggp->id]);

        return compact('me', 'wife', 'wifesCousin', 'myCousin', 'ggUncle', 'dad');
    }

    private function doubtsOn(Media $media, Person $candidate): array
    {
        return array_column(app(\App\Services\Vision\FacePlausibility::class)->doubts(
            $candidate, $media, app(\App\Services\Vision\FacePlausibility::class)->kinDistances($media)
        ), 'code');
    }

    public function test_in_laws_cousins_are_fine_on_a_wedding_photo(): void
    {
        $f = $this->family();
        $wedding = $this->photo('2020-06-01');
        $this->face($wedding, 0.1, $f['me']);

        $this->assertSame([], $this->doubtsOn($wedding, $f['wifesCousin']), 'cousin de ma femme : 5 liens, après le mariage');
        $this->assertSame([], $this->doubtsOn($wedding, $f['myCousin']));
    }

    public function test_in_laws_are_not_family_before_the_marriage(): void
    {
        $f = $this->family();
        $old = $this->photo('2005-06-01');
        $this->face($old, 0.1, $f['me']);

        $this->assertSame(['distant'], $this->doubtsOn($old, $f['wifesCousin']));
    }

    public function test_other_branch_is_odd_on_a_great_grand_uncle_photo(): void
    {
        $f = $this->family();
        $photo = $this->photo('1995-06-01');
        $this->face($photo, 0.1, $f['ggUncle']);

        $this->assertSame(['distant'], $this->doubtsOn($photo, $f['myCousin']), 'cousin par l\'autre branche : 7 liens');
        $this->assertSame([], $this->doubtsOn($photo, $f['dad']), 'côté de l\'oncle : proche');
    }

    public function test_duplicate_resolver_keeps_the_closest_face(): void
    {
        $person = $this->person();
        foreach ([0.10, 0.11, 0.12] as $seed) {
            $this->face($this->photo('2015-01-01'), $seed, $person);
        }
        $media = $this->photo('2016-01-01');
        $good = $this->face($media, 0.11, $person);
        $bad = $this->face($media, 0.50, $person);

        $analysis = app(DuplicateFaceResolver::class)->analysis();
        $this->assertTrue($analysis[$good->id]['suggested']);
        $this->assertTrue($analysis[$good->id]['clear']);

        $this->assertSame(['groups' => 1, 'faces' => 1], app(DuplicateFaceResolver::class)->applyClearSuggestions());
        $this->assertSame($person->id, $good->fresh()->person_id);
        $this->assertNull($bad->fresh()->person_id);
        $this->assertSame('unmatched', $bad->fresh()->status);
    }

    public function test_disassociation_works_on_a_trashed_photo(): void
    {
        $person = $this->person();
        $media = $this->photo('2016-01-01');
        $face = $this->face($media, 0.1, $person);
        $media->people()->attach($person->id, ['face_coordinates' => '{}']);
        $media->delete();

        app(FaceMatcher::class)->disassociate($face->fresh());

        $this->assertNull($face->fresh()->person_id);
        $this->assertDatabaseMissing('media_person', ['media_id' => $media->id, 'person_id' => $person->id]);
    }

    public function test_admin_duplicate_faces_page_lists_duplicates(): void
    {
        $admin = User::factory()->admin()->create();
        $person = $this->person();
        $media = $this->photo('2016-01-01');
        $this->face($media, 0.1, $person);
        $this->face($media, 0.2, $person);

        $this->actingAs($admin);
        Livewire::test(ListDuplicateFaces::class)->assertOk()->assertCanSeeTableRecords(DetectedFace::where('media_id', $media->id)->get());
    }
}
