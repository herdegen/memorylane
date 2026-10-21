<?php

namespace Tests\Feature;

use App\Models\Album;
use App\Models\Media;
use App\Models\Person;
use App\Models\PersonLink;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Réseau (GET /network/data) : liens dérivés des photos, calculés sur les
 * seuls médias visibles du viewer.
 */
class NetworkTest extends TestCase
{
    use RefreshDatabase;

    private function photoWith(User $owner, array $people, string $takenAt = '2015-06-01'): Media
    {
        $media = Media::factory()->create(['user_id' => $owner->id, 'type' => 'photo', 'taken_at' => $takenAt]);
        $media->people()->attach(collect($people)->pluck('id'));

        return $media;
    }

    private function edge(array $graph, Person $a, Person $b): ?array
    {
        [$s, $t] = strcmp($a->id, $b->id) < 0 ? [$a->id, $b->id] : [$b->id, $a->id];

        return collect($graph['edges'])->first(fn ($e) => $e['source'] === $s && $e['target'] === $t);
    }

    public function test_photo_commune_cree_un_lien_pondere_avec_annees(): void
    {
        $user = User::factory()->create();
        [$a, $b, $c] = Person::factory()->count(3)->create(['user_id' => $user->id]);

        $this->photoWith($user, [$a, $b], '2012-03-01');
        $this->photoWith($user, [$a, $b], '2019-08-01');
        $this->photoWith($user, [$b, $c], '2020-01-01');

        $graph = $this->actingAs($user)->getJson('/network/data')->assertOk()->json();

        $ab = $this->edge($graph, $a, $b);
        $this->assertSame(2, $ab['weight']);
        $this->assertSame('photo', $ab['kind']);
        $this->assertSame(2012, $ab['first_year']);
        $this->assertSame(2019, $ab['last_year']);
        $this->assertNotNull($this->edge($graph, $b, $c));
        $this->assertNull($this->edge($graph, $a, $c));

        $nodeB = collect($graph['nodes'])->firstWhere('id', $b->id);
        $this->assertSame(3, $nodeB['photos']);
        $this->assertSame(2012, $nodeB['first_year']);
        $this->assertSame(3, $graph['photo_count']);
    }

    public function test_une_photo_invisible_ne_revele_aucun_lien(): void
    {
        $owner = User::factory()->create();
        $viewer = User::factory()->create();
        [$a, $b] = Person::factory()->count(2)->create(['user_id' => $owner->id]);

        // Photo privée de $owner : invisible pour $viewer.
        $this->photoWith($owner, [$a, $b]);

        $graph = $this->actingAs($viewer)->getJson('/network/data')->assertOk()->json();

        $this->assertSame([], $graph['edges']);
        $this->assertSame([], $graph['nodes']);
    }

    public function test_photo_dans_un_album_partage_avec_le_cercle_compte(): void
    {
        $owner = User::factory()->create();
        $viewer = User::factory()->create();
        [$a, $b] = Person::factory()->count(2)->create(['user_id' => $owner->id]);

        $media = $this->photoWith($owner, [$a, $b]);
        $album = Album::factory()->create(['user_id' => $owner->id]);
        $album->media()->attach($media->id);
        $circle = \App\Models\Household::factory()->create(['created_by' => $owner->id]);
        $circle->members()->attach([$owner->id, $viewer->id]);
        $album->households()->attach($circle->id, ['granted_by' => $owner->id]);

        $graph = $this->actingAs($viewer)->getJson('/network/data')->assertOk()->json();

        $this->assertNotNull($this->edge($graph, $a, $b));
    }

    public function test_meme_album_sans_photo_commune_donne_un_lien_faible(): void
    {
        $user = User::factory()->create();
        [$a, $b] = Person::factory()->count(2)->create(['user_id' => $user->id]);

        $m1 = $this->photoWith($user, [$a], '2016-05-01');
        $m2 = $this->photoWith($user, [$b], '2017-05-01');
        $album = Album::factory()->create(['user_id' => $user->id]);
        $album->media()->attach([$m1->id, $m2->id]);

        $graph = $this->actingAs($user)->getJson('/network/data')->assertOk()->json();

        $ab = $this->edge($graph, $a, $b);
        $this->assertSame('album', $ab['kind']);
        $this->assertSame(1, $ab['weight']);
        $this->assertSame(2017, $ab['first_year']);
    }

    public function test_photo_et_album_communs_ne_donnent_qu_un_lien_fort(): void
    {
        $user = User::factory()->create();
        [$a, $b] = Person::factory()->count(2)->create(['user_id' => $user->id]);

        $media = $this->photoWith($user, [$a, $b]);
        $album = Album::factory()->create(['user_id' => $user->id]);
        $album->media()->attach($media->id);

        $graph = $this->actingAs($user)->getJson('/network/data')->assertOk()->json();

        $this->assertCount(1, $graph['edges']);
        $this->assertSame('photo', $graph['edges'][0]['kind']);
    }

    public function test_photos_ensemble_filtrees_par_visibilite(): void
    {
        $owner = User::factory()->create();
        $viewer = User::factory()->create();
        [$a, $b] = Person::factory()->count(2)->create(['user_id' => $owner->id]);

        $visible = $this->photoWith($viewer, [$a, $b]);
        $this->photoWith($owner, [$a, $b]); // privée au propriétaire

        $this->actingAs($viewer)
            ->getJson("/network/together?a={$a->id}&b={$b->id}")
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.id', $visible->id);
    }

    public function test_fiche_moi_presente_meme_sans_lien(): void
    {
        $user = User::factory()->create();
        $self = Person::factory()->create(['user_id' => $user->id]);
        $user->forceFill(['person_id' => $self->id])->save();

        $graph = $this->actingAs($user)->getJson('/network/data')->assertOk()->json();

        $this->assertSame($self->id, $graph['self_id']);
        $this->assertTrue(collect($graph['nodes'])->firstWhere('id', $self->id)['is_self']);
    }

    public function test_page_reseau_rendue(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/network')->assertOk()
            ->assertInertia(fn ($page) => $page->component('Network/Index', false));
    }

    public function test_une_nouvelle_identification_apparait_tout_de_suite(): void
    {
        $user = User::factory()->create();
        [$a, $b] = Person::factory()->count(2)->create(['user_id' => $user->id]);

        // Premier affichage : réseau vide, mis en cache.
        $this->actingAs($user)->getJson('/network/data')->assertOk()->assertJsonCount(0, 'edges');

        // On identifie deux personnes ensemble : le réseau suit sans attendre le cache.
        $this->photoWith($user, [$a, $b]);

        $this->actingAs($user)->getJson('/network/data')->assertOk()->assertJsonCount(1, 'edges');
    }

    public function test_l_annee_de_rencontre_repondue_remplace_la_premiere_photo(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        [$a, $b, $c] = Person::factory()->count(3)->create(['user_id' => $user->id]);

        $this->photoWith($user, [$a, $b], '2019-08-01');
        $this->photoWith($user, [$b, $c], '2020-01-01');

        // Deux récits pour a–b : celui du compte qui regarde l'emporte.
        [$p1, $p2] = PersonLink::pairKey($a->id, $b->id);
        PersonLink::create(['person1_id' => $p1, 'person2_id' => $p2, 'created_by' => $other->id, 'context' => 'school', 'met_year' => 1998]);
        PersonLink::create(['person1_id' => $p1, 'person2_id' => $p2, 'created_by' => $user->id, 'context' => 'school', 'met_year' => 2003]);

        $graph = $this->actingAs($user)->getJson('/network/data')->assertOk()->json();
        $this->assertSame(2003, $this->edge($graph, $a, $b)['first_year']);
        $this->assertSame(2020, $this->edge($graph, $b, $c)['first_year'], 'sans réponse : première photo');

        // Un autre compte, sans récit à lui : la plus ancienne réponse.
        $graph = $this->actingAs($other)->getJson('/network/data')->json();
        $this->assertSame([], $graph['edges'], 'photos privées de $user');
    }

    public function test_repondre_comment_vous_vous_etes_connus_met_a_jour_le_reseau(): void
    {
        $user = User::factory()->create();
        $me = Person::factory()->create(['user_id' => $user->id]);
        $user->forceFill(['person_id' => $me->id])->save();
        $friend = Person::factory()->create(['user_id' => $user->id]);
        $this->photoWith($user, [$me, $friend], '2021-05-01');

        $this->assertSame(2021, $this->edge($this->actingAs($user)->getJson('/network/data')->json(), $me, $friend)['first_year']);

        $this->actingAs($user)->postJson('/quests/answer', [
            'question_type' => 'how_met',
            'subject_id' => $friend->id,
            'answer_kind' => 'answered',
            'payload' => ['context' => array_key_first(PersonLink::CONTEXTS), 'year' => 2008],
        ])->assertSuccessful();

        // Pas de cache périmé : le réseau suit la réponse immédiatement.
        $this->assertSame(2008, $this->edge($this->actingAs($user)->getJson('/network/data')->json(), $me, $friend)['first_year']);
    }
}
