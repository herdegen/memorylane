<?php

namespace Tests\Feature;

use App\Models\Household;
use App\Models\Media;
use App\Models\Person;
use App\Models\PersonLink;
use App\Models\User;
use App\Services\QuestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Mode amis (phase 3) : quête « comment as-tu connu X ? », récits de
 * rencontre (une version par compte), quêtes sans généalogie, fiches
 * rattachées à un cercle par l'admin.
 */
class FriendsModeTest extends TestCase
{
    use RefreshDatabase;

    private User $me;
    private User $other;
    private User $admin;
    private Household $circle;
    private Person $self;
    private Person $lea;
    private Person $ines;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
        $this->me = User::factory()->create();
        $this->other = User::factory()->create();

        $this->circle = Household::factory()->friends()->create(['created_by' => $this->admin->id]);
        $this->circle->members()->attach([$this->me->id, $this->other->id]);

        $this->self = $this->friend('Matthieu', $this->me);
        $this->me->forceFill(['person_id' => $this->self->id])->save();
        $this->lea = $this->friend('Léa');
        $this->ines = $this->friend('Inès');
    }

    private function friend(string $name, ?User $owner = null): Person
    {
        $p = Person::factory()->create([
            'user_id' => ($owner ?? $this->admin)->id,
            'name' => $name,
            'in_family_directory' => false,
            'gender' => 'F',
            'birth_date' => now()->subYears(30),
        ]);
        $p->circles()->attach($this->circle->id);

        return $p;
    }

    private function photo(array $people, ?User $owner = null): Media
    {
        $m = Media::factory()->create(['user_id' => ($owner ?? $this->me)->id, 'type' => 'photo', 'taken_at' => '2013-05-01']);
        $m->people()->attach(collect($people)->pluck('id'));

        return $m;
    }

    private function keys(User $user): array
    {
        $service = app(QuestService::class);
        $service->forget($user);

        return array_map(fn ($c) => $c['type'].':'.$c['subject_id'], $service->candidates($user));
    }

    public function test_quete_comment_as_tu_connu_pour_un_ami_photographie_avec_soi(): void
    {
        $this->photo([$this->self, $this->lea]);

        $keys = $this->keys($this->me);

        $this->assertContains("how_met:{$this->lea->id}", $keys);
        $this->assertNotContains("how_met:{$this->ines->id}", $keys); // jamais ensemble en photo
    }

    public function test_pas_de_questions_genealogiques_en_mode_amis(): void
    {
        $this->lea->update(['birth_date' => null, 'birth_place' => null]);

        $types = collect($this->keys($this->me))->map(fn ($k) => explode(':', $k)[0])->unique();

        $this->assertContains('birth_date', $types->all());
        foreach (['birth_place', 'parent_father', 'parent_mother', 'marital_status', 'job', 'address'] as $genealogical) {
            $this->assertNotContains($genealogical, $types->all());
        }
    }

    public function test_question_presentee_avec_suggestion_par_un_ami_commun(): void
    {
        // Inès est souvent avec moi ET avec Léa → « par Inès ».
        $this->photo([$this->self, $this->lea]);
        $this->photo([$this->self, $this->ines]);
        $this->photo([$this->self, $this->ines]);
        $this->photo([$this->lea, $this->ines]);

        // Présentation de la question sur Léa (méthode privée, l'ordre du lot
        // étant aléatoire).
        $present = new \ReflectionMethod(QuestService::class, 'present');
        $q = $present->invoke(app(QuestService::class), $this->me->fresh(), \App\Enums\QuestType::HowMet, $this->lea);

        $this->assertNotNull($q);
        $this->assertSame('Comment as-tu connu Léa ?', $q['prompt']);
        $this->assertSame($this->ines->id, $q['how_met']['via']['id']);
        $this->assertSame(1, $q['how_met']['photos']);
    }

    public function test_repondre_enregistre_ma_version_et_n_eteint_pas_la_question_des_autres(): void
    {
        $this->photo([$this->self, $this->lea]);
        $otherSelf = $this->friend('Paul', $this->other);
        $this->other->forceFill(['person_id' => $otherSelf->id])->save();
        $this->photo([$otherSelf, $this->lea], $this->other);

        $this->actingAs($this->me)->postJson('/quests/answer', [
            'question_type' => 'how_met',
            'subject_id' => $this->lea->id,
            'answer_kind' => 'answered',
            'payload' => ['context' => 'studies', 'via_person_id' => $this->ines->id, 'story' => 'Amphi de droit', 'year' => 2012],
        ])->assertCreated();

        $link = PersonLink::forPair($this->self->id, $this->lea->id)->firstOrFail();
        $this->assertSame('studies', $link->context);
        $this->assertSame($this->ines->id, $link->via_person_id);
        $this->assertSame(2012, $link->met_year);

        $this->assertNotContains("how_met:{$this->lea->id}", $this->keys($this->me->fresh()));
        $this->assertContains("how_met:{$this->lea->id}", $this->keys($this->other->fresh()));
    }

    public function test_recits_une_version_par_compte_et_visibilite(): void
    {
        $otherSelf = $this->friend('Paul', $this->other);
        $this->other->forceFill(['person_id' => $otherSelf->id])->save();

        $this->actingAs($this->me)->putJson("/people/{$this->lea->id}/links", ['context' => 'studies', 'story' => 'Ma version'])
            ->assertOk()->assertJsonPath('versions.0.mine', true);

        // Paul raconte SA rencontre avec Léa (autre paire) : n'apparaît pas pour moi.
        $this->actingAs($this->other)->putJson("/people/{$this->lea->id}/links", ['context' => 'work'])->assertOk();
        $this->actingAs($this->me)->getJson("/people/{$this->lea->id}/links")->assertOk()->assertJsonCount(1, 'versions');

        // Paul ajoute sa version de MA rencontre avec Léa (with = moi).
        $this->actingAs($this->other)->putJson("/people/{$this->lea->id}/links", ['with' => $this->self->id, 'context' => 'friend'])->assertOk();
        $this->actingAs($this->me)->getJson("/people/{$this->lea->id}/links")->assertOk()->assertJsonCount(2, 'versions');

        // Hors du cercle, la fiche n'existe pas.
        $outsider = User::factory()->create();
        Household::factory()->friends()->create()->members()->attach($outsider->id);
        $this->actingAs($outsider)->getJson("/people/{$this->lea->id}/links")->assertNotFound();

        // Supprimer ma version.
        $this->actingAs($this->me)->deleteJson("/people/{$this->lea->id}/links")->assertOk()->assertJsonCount(1, 'versions');
    }

    public function test_anniversaires_sans_age_en_mode_amis(): void
    {
        $this->lea->update(['birth_date' => now()->subYears(31)->addDays(2)]);

        $this->actingAs($this->me)->get('/dashboard')->assertOk()
            ->assertInertia(fn ($page) => $page->component('Dashboard', false)
                ->where('celebrations', fn ($list) => collect($list)->contains(fn ($c) => $c['kind'] === 'birthday' && $c['sub'] === 'Dans 2 jours')));
    }

    public function test_l_admin_rattache_une_fiche_existante_au_cercle(): void
    {
        $family = Person::factory()->create(['user_id' => $this->admin->id, 'name' => 'Cousin Pierre']);

        $this->actingAs($this->me)->postJson("/households/{$this->circle->id}/people", ['person_id' => $family->id])->assertForbidden();

        $this->actingAs($this->admin)->getJson("/households/{$this->circle->id}/people/candidates?q=pierre")
            ->assertOk()->assertJsonPath('0.id', $family->id);
        $this->actingAs($this->admin)->postJson("/households/{$this->circle->id}/people", ['person_id' => $family->id])->assertCreated();

        // Les membres du cercle la voient désormais.
        $this->actingAs($this->me)->getJson("/people/{$family->id}/timeline")->assertOk();

        $this->actingAs($this->admin)->deleteJson("/households/{$this->circle->id}/people/{$family->id}")->assertOk();
        $this->actingAs($this->me)->getJson("/people/{$family->id}/timeline")->assertNotFound();
    }

    public function test_qui_est_ce_seulement_sur_les_photos_du_cercle(): void
    {
        // Photo de famille de « moi » (aucun ami dessus) : hors du cercle.
        $familyPhoto = $this->photo([$this->self]);
        $familyFace = \App\Models\DetectedFace::create(['media_id' => $familyPhoto->id, 'status' => 'unmatched', 'bounding_box' => ['x' => 1, 'y' => 1, 'width' => 5, 'height' => 5]]);

        // Photo où Léa (du cercle) est identifiée : dans le cercle.
        $friendPhoto = $this->photo([$this->lea]);
        $friendFace = \App\Models\DetectedFace::create(['media_id' => $friendPhoto->id, 'status' => 'unmatched', 'bounding_box' => ['x' => 1, 'y' => 1, 'width' => 5, 'height' => 5]]);

        // Photo d'un album où un ami figure ailleurs : dans le cercle aussi.
        $album = \App\Models\Album::factory()->create(['user_id' => $this->me->id]);
        $albumPhoto = $this->photo([]);
        $album->media()->attach([$albumPhoto->id, $friendPhoto->id]);
        $albumFace = \App\Models\DetectedFace::create(['media_id' => $albumPhoto->id, 'status' => 'unmatched', 'bounding_box' => ['x' => 1, 'y' => 1, 'width' => 5, 'height' => 5]]);

        $this->me->forceFill(['current_household_id' => $this->circle->id])->save();
        $keys = $this->keys($this->me->fresh());

        $this->assertContains("face_identify:{$friendFace->id}", $keys);
        $this->assertContains("face_identify:{$albumFace->id}", $keys);
        $this->assertNotContains("face_identify:{$familyFace->id}", $keys);
    }

    public function test_photo_partagee_au_cercle_en_fait_partie(): void
    {
        $owner = User::factory()->create();
        $shared = Media::factory()->create(['user_id' => $owner->id, 'type' => 'photo']);
        $album = \App\Models\Album::factory()->create(['user_id' => $owner->id]);
        $album->media()->attach($shared->id);
        $album->households()->attach($this->circle->id);

        $ids = Media::inContext($this->me->fresh())->pluck('id');
        $this->assertTrue($ids->contains($shared->id));
    }
}
