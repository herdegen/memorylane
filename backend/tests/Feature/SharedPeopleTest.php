<?php

namespace Tests\Feature;

use App\Models\Household;
use App\Models\LifeEvent;
use App\Models\Person;
use App\Models\PersonChange;
use App\Models\PersonProposal;
use App\Models\PersonRelationship;
use App\Models\User;
use App\Services\Newsletter\FamilyNewsletterBuilder;
use App\Services\SharedFiches;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Fiches communes à deux familles : hiérarchie des modifications.
 *
 * Joseph (1870) → Marthe (1900), fiche commune aux familles A et B ;
 *   Marthe → Paul (1930) → Alice (1960, compte, famille A) ;
 *   Joseph → Rose (1905) → Hans (1935) → Béa (1965, compte, famille B).
 * B a eu la fiche de Marthe la première, mais A est sa famille référente :
 * Alice est sa petite-fille, Béa sa petite-nièce.
 */
class SharedPeopleTest extends TestCase
{
    use RefreshDatabase;

    private User $alice;

    private User $bea;

    private Household $A;

    private Household $B;

    private array $p = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->alice = User::factory()->create(['name' => 'Alice']);
        $this->bea = User::factory()->create(['name' => 'Béa']);
        $this->A = Household::factory()->create(['name' => 'Famille A', 'created_by' => $this->alice->id]);
        $this->B = Household::factory()->create(['name' => 'Famille B', 'created_by' => $this->bea->id]);
        $this->A->members()->attach($this->alice->id);
        $this->B->members()->attach($this->bea->id);
        $this->alice->forceFill(['current_household_id' => $this->A->id])->save();
        $this->bea->forceFill(['current_household_id' => $this->B->id])->save();

        $make = function (string $key, User $by, string $birth, ?string $father = null, ?string $mother = null, array $extra = []) {
            $this->p[$key] = Person::factory()->create(array_merge([
                'user_id' => $by->id, 'name' => ucfirst($key), 'first_name' => ucfirst($key), 'last_name' => 'Martin',
                'gender' => 'F', 'birth_date' => $birth, 'birth_place' => null, 'death_date' => null, 'death_place' => null,
                'father_id' => $father ? $this->p[$father]->id : null, 'mother_id' => $mother ? $this->p[$mother]->id : null,
            ], $extra));
        };
        $make('joseph', $this->alice, '1870-01-01', extra: ['gender' => 'M']);
        $make('marthe', $this->alice, '1900-03-02', 'joseph', extra: ['birth_place' => 'Metz']);
        $make('paul', $this->alice, '1930-01-01', null, 'marthe', ['gender' => 'M']);
        $make('alice', $this->alice, '1960-01-01', 'paul');
        $make('rose', $this->bea, '1905-01-01', 'joseph');
        $make('hans', $this->bea, '1935-01-01', null, 'rose', ['gender' => 'M']);
        $make('bea', $this->bea, '1965-01-01', 'hans');
        $this->alice->forceFill(['person_id' => $this->p['alice']->id])->save();
        $this->bea->forceFill(['person_id' => $this->p['bea']->id])->save();

        // Joseph et Marthe : communs ; B les a eus en premier.
        foreach (['joseph', 'marthe'] as $key) {
            $this->p[$key]->circles()->syncWithoutDetaching([$this->B->id]);
            DB::table('household_person')->where('person_id', $this->p[$key]->id)->where('household_id', $this->B->id)
                ->update(['created_at' => now()->subYear()]);
        }
    }

    /** Ce qu'envoie le formulaire de la fiche, avec $changes. */
    private function form(Person $person, array $changes): array
    {
        $person->refresh();

        return array_merge([
            'first_name' => $person->first_name, 'last_name' => $person->last_name, 'maiden_name' => $person->maiden_name,
            'gender' => $person->gender, 'birth_date' => $person->birth_date?->format('Y-m-d'), 'birth_place' => $person->birth_place,
            'death_date' => $person->death_date?->format('Y-m-d'), 'death_place' => $person->death_place, 'notes' => $person->notes,
        ], $changes);
    }

    public function test_la_famille_referente_est_la_plus_proche_par_le_sang(): void
    {
        $shared = app(SharedFiches::class);

        $this->assertTrue($shared->isShared($this->p['marthe']));
        $this->assertSame($this->A->id, $shared->referent($this->p['marthe'])->id);
        $this->assertTrue($shared->isReferentMember($this->alice, $this->p['marthe']));
        $this->assertFalse($shared->isReferentMember($this->bea, $this->p['marthe']));
        $this->assertFalse($shared->isShared($this->p['paul']));

        // La personne elle-même, avec son compte, est référente de sa fiche.
        $this->p['bea']->circles()->syncWithoutDetaching([$this->A->id]);
        $this->assertSame($this->B->id, app(SharedFiches::class)->referent($this->p['bea'])->id);
    }

    public function test_la_referente_corrige_directement(): void
    {
        $this->actingAs($this->alice)->putJson("/people/{$this->p['marthe']->id}", $this->form($this->p['marthe'], ['birth_place' => 'Nancy']))
            ->assertOk()->assertJsonPath('proposed', null);

        $this->assertSame('Nancy', $this->p['marthe']->fresh()->birth_place);
        $this->assertSame(0, PersonProposal::count());
    }

    public function test_une_autre_famille_complete_directement_mais_propose_ses_corrections(): void
    {
        $this->actingAs($this->bea)->putJson("/people/{$this->p['marthe']->id}", $this->form($this->p['marthe'], [
            'death_place' => 'Vichy',         // vide : ajout direct
            'birth_place' => 'Nancy',          // correction : proposée
            'source' => 'Acte de naissance, AD Moselle',
        ]))
            ->assertOk()
            ->assertJsonPath('proposed.fields', ['birth_place'])
            ->assertJsonPath('message', 'Fiche commune : la correction est proposée à Famille A, qui la validera.');

        $marthe = $this->p['marthe']->fresh();
        $this->assertSame('Vichy', $marthe->death_place);
        $this->assertSame('Metz', $marthe->birth_place);
        $proposal = PersonProposal::sole();
        $this->assertSame(['fields' => ['birth_place' => ['Metz', 'Nancy']]], $proposal->changes);
        $this->assertSame($this->B->id, $proposal->household_id);
        $this->assertSame('Acte de naissance, AD Moselle', $proposal->source);

        // La même proposition renvoyée n'est pas doublée.
        $this->actingAs($this->bea)->putJson("/people/{$this->p['marthe']->id}", $this->form($marthe, ['birth_place' => 'Nancy']))->assertOk();
        $this->assertSame(1, PersonProposal::count());
    }

    public function test_la_referente_accepte_ou_refuse(): void
    {
        $this->actingAs($this->bea)->putJson("/people/{$this->p['marthe']->id}", $this->form($this->p['marthe'], ['birth_place' => 'Nancy']));
        $proposal = PersonProposal::sole();

        // Qui propose ne tranche pas.
        $this->actingAs($this->bea)->postJson("/shared-people/proposals/{$proposal->id}/accept")->assertForbidden();

        $this->actingAs($this->alice)->postJson("/shared-people/proposals/{$proposal->id}/accept")->assertOk();
        $this->assertSame('Nancy', $this->p['marthe']->fresh()->birth_place);
        $this->assertSame(PersonProposal::ACCEPTED, $proposal->fresh()->status);
        // L'historique la rattache à la proposition (pas une « nouveauté » de plus).
        $this->assertSame($proposal->id, PersonChange::where('person_id', $this->p['marthe']->id)->latest('created_at')->first()->proposal_id);

        // Une autre, refusée : rien ne change.
        $this->actingAs($this->bea)->putJson("/people/{$this->p['marthe']->id}", $this->form($this->p['marthe'], ['birth_date' => '1901-03-02']));
        $second = PersonProposal::pending()->sole();
        $this->actingAs($this->alice)->postJson("/shared-people/proposals/{$second->id}/reject", ['message' => 'Son acte dit 1900.'])->assertOk();
        $this->assertSame('1900-03-02', $this->p['marthe']->fresh()->birth_date->format('Y-m-d'));
        $this->assertSame(PersonProposal::REJECTED, $second->fresh()->status);
        $this->assertSame('Son acte dit 1900.', $second->messages()->sole()->body);
    }

    public function test_les_parents_d_une_fiche_commune_se_proposent(): void
    {
        $other = Person::factory()->create(['user_id' => $this->bea->id, 'name' => 'Autre', 'gender' => 'M']);
        $other->circles()->syncWithoutDetaching([$this->B->id]);

        // L'autre famille : même un parent à remplacer se propose.
        $this->actingAs($this->bea)->postJson("/people/{$this->p['marthe']->id}/parent", ['parent_id' => $other->id, 'parent_type' => 'father'])
            ->assertStatus(202)->assertJsonPath('proposed.id', PersonProposal::sole()->id);
        $this->assertSame($this->p['joseph']->id, $this->p['marthe']->fresh()->father_id);

        // La référente complète un parent manquant directement…
        $mother = Person::factory()->create(['user_id' => $this->alice->id, 'name' => 'Mère', 'gender' => 'F']);
        $this->actingAs($this->alice)->postJson("/people/{$this->p['marthe']->id}/parent", ['parent_id' => $mother->id, 'parent_type' => 'mother'])->assertOk();
        $this->assertSame($mother->id, $this->p['marthe']->fresh()->mother_id);

        // … mais en retirer un se propose aux autres familles.
        $this->actingAs($this->alice)->deleteJson("/people/{$this->p['marthe']->id}/parent", ['parent_type' => 'father'])->assertStatus(202);
        $fromA = PersonProposal::where('household_id', $this->A->id)->sole();
        $this->assertSame([$this->B->id], app(SharedFiches::class)->deciderFamilyIds($fromA));
        $this->actingAs($this->alice)->postJson("/shared-people/proposals/{$fromA->id}/accept")->assertForbidden();
        $this->actingAs($this->bea)->postJson("/shared-people/proposals/{$fromA->id}/accept")->assertOk();
        $this->assertNull($this->p['marthe']->fresh()->father_id);
    }

    public function test_un_nouveau_parent_accepte_entre_dans_les_familles_de_la_fiche(): void
    {
        $other = Person::factory()->create(['user_id' => $this->bea->id, 'name' => 'Grand-mère', 'gender' => 'F']);
        $this->assertSame([$this->B->id], $other->circles()->pluck('households.id')->all());

        $this->actingAs($this->bea)->postJson("/people/{$this->p['marthe']->id}/parent", ['parent_id' => $other->id, 'parent_type' => 'mother'])->assertStatus(202);
        $this->actingAs($this->alice)->postJson('/shared-people/proposals/'.PersonProposal::sole()->id.'/accept')->assertOk();

        $this->assertSame($other->id, $this->p['marthe']->fresh()->mother_id);
        $this->assertTrue(Person::visibleTo($this->alice->fresh())->whereKey($other->id)->exists());
    }

    public function test_je_ne_suis_pas_d_accord(): void
    {
        // La référente corrige ; l'autre famille le voit dans ses nouveautés.
        $this->actingAs($this->alice)->putJson("/people/{$this->p['marthe']->id}", $this->form($this->p['marthe'], ['birth_place' => 'Nancy']));
        $change = PersonChange::where('person_id', $this->p['marthe']->id)->where('action', 'updated')->latest('created_at')->first();

        $this->actingAs($this->bea)->get('/shared-people')->assertOk()
            ->assertInertia(fn ($page) => $page->component('SharedPeople/Index', false)
                ->has('news', 1)
                ->where('news.0.family', 'Famille A')
                ->where('news.0.can_dispute', true)
                ->where('families.0.name', 'Famille A')
                ->where('families.0.count', 2));
        // Pas de désaccord avec soi-même.
        $this->actingAs($this->alice)->postJson("/shared-people/changes/{$change->id}/dispute", ['message' => 'Non'])->assertForbidden();

        $this->actingAs($this->bea)->postJson("/shared-people/changes/{$change->id}/dispute", ['message' => 'Elle est née à Metz, j’ai son acte.'])->assertOk();
        $this->assertSame('Metz', $this->p['marthe']->fresh()->birth_place);
        $dispute = PersonProposal::sole();
        $this->assertTrue($dispute->isDispute());
        $this->assertNotNull($change->fresh()->reverted_at);
        $this->assertSame('Elle est née à Metz, j’ai son acte.', $dispute->messages()->sole()->body);

        // La discussion continue, puis la référente tranche : elle maintient.
        $this->actingAs($this->alice)->postJson("/shared-people/proposals/{$dispute->id}/messages", ['message' => 'Le livret de famille dit Nancy.'])->assertOk();
        $this->actingAs($this->alice)->postJson("/shared-people/proposals/{$dispute->id}/accept")->assertOk();
        $this->assertSame('Nancy', $this->p['marthe']->fresh()->birth_place);
        $this->assertSame(2, $dispute->messages()->count());
    }

    public function test_qui_conteste_peut_se_ranger_a_la_modification(): void
    {
        $this->actingAs($this->alice)->putJson("/people/{$this->p['marthe']->id}", $this->form($this->p['marthe'], ['birth_place' => 'Nancy']));
        $change = PersonChange::where('person_id', $this->p['marthe']->id)->where('action', 'updated')->latest('created_at')->first();
        $this->actingAs($this->bea)->postJson("/shared-people/changes/{$change->id}/dispute", ['message' => 'Sûr ?'])->assertOk();

        $this->actingAs($this->bea)->postJson('/shared-people/proposals/'.PersonProposal::sole()->id.'/accept')->assertOk();
        $this->assertSame('Nancy', $this->p['marthe']->fresh()->birth_place);
    }

    public function test_defaire_une_union_d_une_fiche_commune_se_propose(): void
    {
        $ids = [$this->p['joseph']->id, $this->p['marthe']->id];
        sort($ids);
        $spouse = Person::factory()->create(['user_id' => $this->alice->id, 'name' => 'Louis', 'gender' => 'M']);
        $spouse->circles()->syncWithoutDetaching([$this->B->id]);
        $this->actingAs($this->alice)->postJson("/people/{$this->p['marthe']->id}/spouse", ['spouse_id' => $spouse->id])->assertOk();

        $this->actingAs($this->bea)->deleteJson("/people/{$this->p['marthe']->id}/spouse", ['spouse_id' => $spouse->id])->assertStatus(202);
        $this->assertSame(1, PersonRelationship::count());

        $proposal = PersonProposal::sole();
        $this->assertSame('Louis', $proposal->changes['remove_spouse']['name']);
        $this->actingAs($this->alice)->postJson("/shared-people/proposals/{$proposal->id}/accept")->assertOk();
        $this->assertSame(0, PersonRelationship::count());
    }

    public function test_supprimer_une_fiche_commune_la_retire_de_sa_famille(): void
    {
        $this->actingAs($this->alice)->deleteJson("/people/{$this->p['marthe']->id}")
            ->assertOk()->assertJson(['detached' => true]);

        $marthe = Person::withTrashed()->find($this->p['marthe']->id);
        $this->assertFalse($marthe->trashed());
        $this->assertSame([$this->B->id], $marthe->circles()->pluck('households.id')->all());
    }

    public function test_un_moment_saisi_par_une_autre_famille(): void
    {
        $event = LifeEvent::create(['person_id' => $this->p['marthe']->id, 'user_id' => $this->alice->id,
            'type' => 'residence', 'title' => 'Metz', 'event_date' => '1920-01-01']);
        $payload = ['type' => 'residence', 'title' => 'Nancy', 'event_date' => '1920-01-01'];

        $this->actingAs($this->bea)->putJson("/life-events/{$event->id}", $payload)->assertForbidden();

        // Le sien, oui.
        $this->actingAs($this->bea)->postJson("/people/{$this->p['marthe']->id}/events", $payload)->assertCreated();
        $own = LifeEvent::where('user_id', $this->bea->id)->sole();
        $this->actingAs($this->bea)->putJson("/life-events/{$own->id}", ['title' => 'Nancy, rue Haute'] + $payload)->assertOk();
        // La référente, sur tous.
        $this->actingAs($this->alice)->putJson("/life-events/{$own->id}", $payload)->assertOk();
    }

    public function test_pastille_et_lettre_du_dimanche(): void
    {
        $this->actingAs($this->bea)->putJson("/people/{$this->p['marthe']->id}", $this->form($this->p['marthe'], ['birth_place' => 'Nancy', 'death_place' => 'Vichy']));

        $counts = app(SharedFiches::class)->counts($this->alice->fresh());
        $this->assertSame(['shared' => 2, 'to_decide' => 1, 'news' => 1], $counts);

        $letter = app(FamilyNewsletterBuilder::class)->build($this->alice->fresh());
        $section = collect($letter['sections'])->firstWhere('key', 'shared_people');
        $this->assertSame(1, $section['to_decide']);
        $this->assertSame(1, $section['changed']);

        // Page vue : plus de nouveauté, la proposition reste à trancher.
        $this->actingAs($this->alice)->get('/shared-people')->assertOk()
            ->assertInertia(fn ($page) => $page->has('toDecide', 1)->where('toDecide.0.can_decide', true)
                ->where('toDecide.0.changes.0.old', 'Metz')->where('toDecide.0.changes.0.new', 'Nancy'));
        $this->assertSame(['shared' => 2, 'to_decide' => 1, 'news' => 0], app(SharedFiches::class)->counts($this->alice->fresh()));

        // Rien de commun : pas de pastille à montrer.
        $solo = User::factory()->create();
        $this->assertNull(app(SharedFiches::class)->counts($solo));
    }

    public function test_la_fiche_dit_qu_elle_est_commune(): void
    {
        $this->actingAs($this->bea)->get("/people/{$this->p['marthe']->id}")->assertOk()
            ->assertInertia(fn ($page) => $page->where('shared.referent', 'Famille A')->where('shared.is_referent', false)->has('shared.families', 2));
        $this->actingAs($this->alice)->get("/people/{$this->p['paul']->id}")->assertOk()
            ->assertInertia(fn ($page) => $page->where('shared', null));
    }
}
